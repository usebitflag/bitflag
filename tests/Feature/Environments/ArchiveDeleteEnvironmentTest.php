<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Environments\Actions\ArchiveEnvironment;
use App\Modules\Environments\Actions\CreateEnvironment;
use App\Modules\Environments\Actions\DeleteEnvironment;
use App\Modules\Environments\Data\ArchiveEnvironmentData;
use App\Modules\Environments\Data\CreateEnvironmentData;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Environments\Queries\EnvironmentDeleteBlockers;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Redis::flushdb();
    $this->p = project_with_three();
    // Give the environments the policy a project made in the portal has.
    Tenancy::crossTenant('inspect', function () {
        foreach (Environment::query()->whereNull('current_policy_id')->get() as $environment) {
            $policy = EnvironmentPolicy::query()->create(['organization_id' => $environment->organization_id, 'project_id' => $environment->project_id, 'environment_id' => $environment->id, 'version' => 1]);
            $environment->forceFill(['current_policy_id' => $policy->id])->save();
        }
    });
});

function target(array $p, string $key, ?string $typed = null): ArchiveEnvironmentData
{
    return new ArchiveEnvironmentData($p['organization']->id, $p['project']->id, $p['ids'][$key], $typed ?? $key);
}

function env_row(array $p, string $key): ?Environment
{
    return Tenancy::crossTenant('inspect', fn () => Environment::query()->find($p['ids'][$key]));
}

function key_in(array $p, string $key, string $type = 'server'): array
{
    $p['environment'] = env_row($p, $key);

    return make_credential($p, [], CredentialType::from($type));
}

describe('archiving an environment', function () {
    it('revokes every working key at once, keeps history and renumbers the rest', function () {
        $server = key_in($this->p, 'staging');
        $management = key_in($this->p, 'staging', 'management');
        $other = key_in($this->p, 'production');
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($server['secret']);

        app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging'));

        $row = env_row($this->p, 'staging');

        expect($row->status->value)->toBe('archived')
            ->and($row->archived_at)->not->toBeNull()
            ->and($row->archived_by)->toBe($this->p['user']->id)
            ->and(positions($this->p))->toBe(['development' => 1, 'production' => 2, 'staging' => 3])
            ->and(fn () => $resolver->resolve($server['secret']))->toThrow(RevokedCredential::class)
            ->and(fn () => $resolver->resolve($management['secret']))->toThrow(RevokedCredential::class)
            ->and($resolver->resolve($other['secret'])->status)->toBe(CredentialStatus::Active)
            ->and(credential_now($server['credential'])->revoke_reason?->value)->toBe('environment_archived')
            ->and(credential_now($server['credential'])->revoked_by)->toBeNull()
            ->and(Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', 'key.revoked')->count()))->toBe(2)
            ->and(Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', 'internal.environment.archived')->count()))->toBe(1)
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'environment.archived')->firstOrFail()->details['revoked_keys']))->toBe(2);
    });

    it('stops new keys and no longer counts toward the plan', function () {
        app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging'));

        $created = app(CreateEnvironment::class)->handle($this->p['actor'], new CreateEnvironmentData($this->p['organization']->id, $this->p['project']->id, 'QA', 'qa', 'slate', null, false));

        expect(positions($this->p))->toBe(['development' => 1, 'production' => 2, 'qa' => 3, 'staging' => 4])
            ->and($created)->not->toBeEmpty();
    });

    it('needs the key typed, an active environment and a second active one', function () {
        expect(fn () => app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging', 'nope')))->toThrow(ValidationException::class)
            ->and(env_row($this->p, 'staging')->status->value)->toBe('active');

        app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging'));
        expect(fn () => app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging')))->toThrow(ValidationException::class);

        app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'production'));
        expect(fn () => app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'development')))->toThrow(ValidationException::class)
            ->and(env_row($this->p, 'development')->status->value)->toBe('active');
    });

    it('is for people who manage environments', function (?ProjectRole $role) {
        $viewer = person(OrgRole::Member, $role);

        expect(fn () => app(ArchiveEnvironment::class)->handle($viewer['actor'], new ArchiveEnvironmentData($this->p['organization']->id, $this->p['project']->id, $this->p['ids']['staging'], 'staging')))->toThrow(AuthorizationDenied::class);
    })->with([[ProjectRole::Publisher], [ProjectRole::Developer], [ProjectRole::Viewer], [null]]);

    it('rolls back, keys included, when the audit entry cannot be written', function () {
        $server = key_in($this->p, 'staging');
        app()->instance(AuditCatalog::class, new AuditCatalog);
        app()->forgetInstance(AuditRecorder::class);

        expect(fn () => app(ArchiveEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging')))->toThrow(UnknownAuditAction::class)
            ->and(env_row($this->p, 'staging')->status->value)->toBe('active')
            ->and(credential_now($server['credential'])->status)->toBe(CredentialStatus::Active);
    });
});

describe('deleting an environment', function () {
    it('removes a never-published environment without keys, with its policy rows', function () {
        app(DeleteEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging'));

        expect(env_row($this->p, 'staging'))->toBeNull()
            ->and(positions($this->p))->toBe(['development' => 1, 'production' => 2])
            ->and(Tenancy::crossTenant('inspect', fn () => EnvironmentPolicy::query()->where('environment_id', $this->p['ids']['staging'])->count()))->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'environment.deleted')->count()))->toBe(1);
    });

    it('refuses an environment that was published or ever had a key, even a revoked one', function (callable $history) {
        $history($this->p);

        expect(fn () => app(DeleteEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging')))->toThrow(ValidationException::class)
            ->and(env_row($this->p, 'staging'))->not->toBeNull();
    })->with([
        'published' => [fn (array $p) => Tenancy::crossTenant('inspect', fn () => Environment::query()->whereKey($p['ids']['staging'])->update(['first_published_at' => now()]))],
        'an active key' => [fn (array $p) => key_in($p, 'staging')],
        'a revoked key' => [fn (array $p) => Tenancy::crossTenant('inspect', fn () => key_in($p, 'staging')['credential']->forceFill(['status' => 'revoked'])->save())],
    ]);

    it('lists what blocks it', function () {
        key_in($this->p, 'staging');
        key_in($this->p, 'staging');
        Tenancy::crossTenant('inspect', fn () => Environment::query()->whereKey($this->p['ids']['staging'])->update(['first_published_at' => now()]));

        $blockers = Tenancy::crossTenant('inspect', fn () => app(EnvironmentDeleteBlockers::class)->for($this->p['ids']['staging']));
        $blockers = app(TenantContext::class)->run($this->p['organization']->id, fn () => app(EnvironmentDeleteBlockers::class)->for($this->p['ids']['staging']));

        expect($blockers)->toBe(['Published revisions', '2 active SDK keys', 'Credential history for this environment']);
    });

    it('needs the key typed and the project keeps one environment', function () {
        expect(fn () => app(DeleteEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging', 'x')))->toThrow(ValidationException::class);

        app(DeleteEnvironment::class)->handle($this->p['actor'], target($this->p, 'staging'));
        app(DeleteEnvironment::class)->handle($this->p['actor'], target($this->p, 'production'));

        expect(fn () => app(DeleteEnvironment::class)->handle($this->p['actor'], target($this->p, 'development')))->toThrow(ValidationException::class);
    });

    it('is for people who manage environments', function () {
        $viewer = person(OrgRole::Member, ProjectRole::Viewer);

        expect(fn () => app(DeleteEnvironment::class)->handle($viewer['actor'], new ArchiveEnvironmentData($this->p['organization']->id, $this->p['project']->id, $this->p['ids']['staging'], 'staging')))->toThrow(AuthorizationDenied::class);
    });
});
