<?php

declare(strict_types=1);

use App\Modules\Environments\Actions\UpdateEnvironmentSettings;
use App\Modules\Environments\Data\UpdateEnvironmentSettingsData;
use App\Modules\Environments\Events\EnvironmentPolicyUpdated;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Plans\Entitlements;
use App\Shared\Plans\Feature;
use App\Shared\Plans\FeatureNotInPlan;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * An Owner, and a staging environment with policy version 1 (open).
 *
 * @return array<string, mixed>
 */
function owner_with_environment(): array
{
    $p = person(OrgRole::Member, ProjectRole::Owner);
    Tenancy::crossTenant('inspect', function () use ($p) {
        $p['environment']->forceFill(['name' => 'Staging', 'key' => 'staging', 'allowed_origins' => []])->save();
        $policy = EnvironmentPolicy::query()->create(['organization_id' => $p['organization']->id, 'project_id' => $p['project']->id, 'environment_id' => $p['environment']->id, 'version' => 1]);
        $p['environment']->forceFill(['current_policy_id' => $policy->id])->save();
    });

    return $p;
}

function settings(array $p, array $change = []): UpdateEnvironmentSettingsData
{
    return new UpdateEnvironmentSettingsData(...[
        'organizationId' => $p['organization']->id,
        'projectId' => $p['project']->id,
        'environmentId' => $p['environment']->id,
        'name' => 'Staging',
        'key' => 'staging',
        'color' => 'slate',
        'allowedOrigins' => [],
        'defaultMaxSnapshotAgeSeconds' => null,
        'isProtected' => false,
        'requiredApprovals' => 0,
        'expectedVersion' => 1,
        ...$change,
    ]);
}

function env_now(array $p): Environment
{
    return Tenancy::crossTenant('inspect', fn () => Environment::query()->findOrFail($p['environment']->id));
}

function policies_of(array $p): array
{
    return Tenancy::crossTenant('inspect', fn () => EnvironmentPolicy::query()->where('environment_id', $p['environment']->id)->orderBy('version')->get()->all());
}

function audit_of(string $action): array
{
    return Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', $action)->get()->all());
}

function deny_approvals(): void
{
    app()->bind(Entitlements::class, fn () => new class implements Entitlements
    {
        public function assertAllows(string $organizationId, Feature $feature): void
        {
            throw new FeatureNotInPlan($feature);
        }

        public function allows(string $organizationId, Feature $feature): bool
        {
            return false;
        }
    });
}

describe('saving environment settings', function () {
    it('saves the fields, bumps the version and records what changed', function () {
        $p = owner_with_environment();

        $version = app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, [
            'name' => ' Pre-prod ',
            'key' => 'pre-prod',
            'color' => 'indigo',
            'allowedOrigins' => ['HTTPS://App.Example.com/', 'http://localhost:3000'],
            'defaultMaxSnapshotAgeSeconds' => 3600,
        ]));

        $environment = env_now($p);
        $audit = audit_of('environment.updated');

        expect($version)->toBe(2)
            ->and($environment->name)->toBe('Pre-prod')
            ->and($environment->key)->toBe('pre-prod')
            ->and($environment->color->value)->toBe('indigo')
            ->and($environment->allowed_origins)->toBe(['https://app.example.com', 'http://localhost:3000'])
            ->and($environment->default_max_snapshot_age_seconds)->toBe(3600)
            ->and($audit)->toHaveCount(1)
            ->and($audit[0]->details['changed'])->toBe(['name', 'key', 'color', 'allowed_origins', 'default_max_snapshot_age_seconds'])
            ->and($audit[0]->details['before']['color'])->toBe('slate')
            ->and($audit[0]->details['after']['color'])->toBe('indigo');
    });

    it('records delivery settings changes only when origins or the snapshot age change', function () {
        $p = owner_with_environment();
        $count = fn () => Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', 'internal.environment.delivery_settings_changed')->count());

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'Renamed']));
        expect($count())->toBe(0);

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'Renamed', 'allowedOrigins' => ['https://a.example.com'], 'expectedVersion' => 2]));
        expect($count())->toBe(1);
    });

    it('is for people who manage environments', function (?ProjectRole $role) {
        $p = person(OrgRole::Member, $role);

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p)))->toThrow(AuthorizationDenied::class);
    })->with([
        'publisher' => [ProjectRole::Publisher],
        'developer' => [ProjectRole::Developer],
        'viewer' => [ProjectRole::Viewer],
        'no role' => [null],
    ]);

    it('refuses values the form cannot send', function (array $change, string $field) {
        $p = owner_with_environment();

        try {
            app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, $change));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }

        expect(env_now($p)->version)->toBe(1);
    })->with([
        'empty name' => [['name' => ' '], 'name'],
        'bad key' => [['key' => 'Bad Key'], 'key'],
        'unknown color' => [['color' => 'red'], 'color'],
        'snapshot age' => [['defaultMaxSnapshotAgeSeconds' => 60], 'max_snapshot_age'],
        'origin with a path' => [['allowedOrigins' => ['https://a.example.com/app']], 'allowed_origins'],
        'plain http' => [['allowedOrigins' => ['http://a.example.com']], 'allowed_origins'],
        'duplicate origin' => [['allowedOrigins' => ['https://a.example.com', 'https://A.example.com/']], 'allowed_origins'],
        'too many origins' => [['allowedOrigins' => array_map(fn (int $i) => "https://h{$i}.example.com", range(1, 21))], 'allowed_origins'],
    ]);

    it('refuses a key another environment of the project uses', function () {
        $p = owner_with_environment();
        Environment::factory()->create(['project_id' => $p['project']->id, 'organization_id' => $p['organization']->id, 'key' => 'production', 'position' => 2]);

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['key' => 'production'])))->toThrow(ValidationException::class);
    });

    it('fixes the key after the first publication but allows the other settings', function () {
        $p = owner_with_environment();
        Tenancy::crossTenant('inspect', fn () => Environment::query()->whereKey($p['environment']->id)->update(['first_published_at' => now()]));

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['key' => 'renamed'])))->toThrow(ValidationException::class);

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'Renamed']));

        expect(env_now($p)->name)->toBe('Renamed')->and(env_now($p)->key)->toBe('staging');
    });

    it('does not change an archived environment', function () {
        $p = owner_with_environment();
        Tenancy::crossTenant('inspect', fn () => Environment::query()->whereKey($p['environment']->id)->update(['status' => 'archived']));

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'Nope'])))->toThrow(ValidationException::class);
    });

    it('reports a version conflict and changes nothing', function () {
        $p = owner_with_environment();
        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'First']));

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'Second'])))->toThrow(VersionConflict::class)
            ->and(env_now($p)->name)->toBe('First');
    });

    it('rolls back, policy included, when the audit entry cannot be written', function () {
        $p = owner_with_environment();
        app()->instance(AuditCatalog::class, new AuditCatalog);
        app()->forgetInstance(AuditRecorder::class);

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'Lost', 'isProtected' => true])))->toThrow(UnknownAuditAction::class)
            ->and(env_now($p)->name)->toBe('Staging')
            ->and(policies_of($p))->toHaveCount(1);
    });
});

describe('protection policy versions', function () {
    it('inserts the next version on a protection change and moves the environment to it', function () {
        $p = owner_with_environment();
        Event::fake([EnvironmentPolicyUpdated::class]);

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['isProtected' => true]));

        $policies = policies_of($p);
        $audit = audit_of('environment.policy_changed');

        expect($policies)->toHaveCount(2)
            ->and($policies[1]->version)->toBe(2)
            ->and($policies[1]->is_protected)->toBeTrue()
            ->and($policies[1]->require_reason)->toBeTrue()
            ->and($policies[0]->is_protected)->toBeFalse()
            ->and(env_now($p)->current_policy_id)->toBe($policies[1]->id)
            ->and($audit)->toHaveCount(1)
            ->and($audit[0]->details['before']['is_protected'])->toBeFalse()
            ->and($audit[0]->details['after']['policy_version'])->toBe(2);

        Event::assertDispatched(EnvironmentPolicyUpdated::class, fn (EnvironmentPolicyUpdated $event) => $event->environmentId === $p['environment']->id && $event->policyVersion === 2);
    });

    it('does not insert a version for edits that leave protection alone', function () {
        $p = owner_with_environment();
        Event::fake([EnvironmentPolicyUpdated::class]);

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['name' => 'Renamed', 'allowedOrigins' => ['https://a.example.com']]));

        expect(policies_of($p))->toHaveCount(1)
            ->and(audit_of('environment.policy_changed'))->toBeEmpty();
        Event::assertNotDispatched(EnvironmentPolicyUpdated::class);
    });

    it('requires approvals only while protected, and clears them when protection goes off', function () {
        $p = owner_with_environment();

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['isProtected' => false, 'requiredApprovals' => 1]));
        expect(policies_of($p))->toHaveCount(1);

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['expectedVersion' => 2, 'isProtected' => true, 'requiredApprovals' => 1]));
        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['expectedVersion' => 3, 'isProtected' => false, 'requiredApprovals' => 1]));

        $policies = policies_of($p);

        expect($policies)->toHaveCount(3)
            ->and($policies[1]->required_approvals)->toBe(1)
            ->and($policies[2]->is_protected)->toBeFalse()
            ->and($policies[2]->required_approvals)->toBe(0)
            ->and($policies[2]->require_reason)->toBeFalse();
    });

    it('needs a plan with required approvals to raise them, but not to lower them', function () {
        $p = owner_with_environment();
        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['isProtected' => true, 'requiredApprovals' => 1]));
        deny_approvals();

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['expectedVersion' => 2, 'isProtected' => true, 'requiredApprovals' => 1, 'name' => 'Same policy'])))->not->toThrow(FeatureNotInPlan::class);

        app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['expectedVersion' => 3, 'name' => 'Same policy', 'isProtected' => true, 'requiredApprovals' => 0]));
        expect(policies_of($p))->toHaveCount(3);

        expect(fn () => app(UpdateEnvironmentSettings::class)->handle($p['actor'], settings($p, ['expectedVersion' => 4, 'name' => 'Same policy', 'isProtected' => true, 'requiredApprovals' => 1])))->toThrow(FeatureNotInPlan::class)
            ->and(policies_of($p))->toHaveCount(3);
    });

    it('never updates or deletes a policy row', function () {
        $p = owner_with_environment();
        $policy = policies_of($p)[0];

        expect(fn () => Tenancy::crossTenant('inspect', fn () => $policy->forceFill(['is_protected' => true])->save()))->toThrow(LogicException::class)
            ->and(fn () => Tenancy::crossTenant('inspect', fn () => $policy->delete()))->toThrow(LogicException::class);
    });
});
