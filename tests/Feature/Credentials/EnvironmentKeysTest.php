<?php

declare(strict_types=1);

use App\Modules\Credentials\Actions\CompleteRotation;
use App\Modules\Credentials\Actions\ExpireCredential;
use App\Modules\Credentials\Actions\IssueCredential;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Actions\RotateCredential;
use App\Modules\Credentials\Data\IssueCredentialData;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Data\RotateCredentialData;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Credentials\Queries\DueCredentials;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\EmailNotVerified;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Plans\PlanRestrictionGate;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Redis::flushdb();
    $this->p = person(OrgRole::Member, ProjectRole::Owner);
    Tenancy::crossTenant('inspect', fn () => $this->p['environment']->forceFill(['key' => 'production'])->save());
});

function issue(array $p, array $change = [], ?Actor $actor = null)
{
    return app(IssueCredential::class)->handle($actor ?? $p['actor'], new IssueCredentialData(...[
        'organizationId' => $p['organization']->id,
        'projectId' => $p['project']->id,
        'environmentId' => $p['environment']->id,
        'type' => 'server',
        'label' => 'Checkout API',
        'scopes' => ['snapshot:read'],
        'ttlDays' => null,
        ...$change,
    ]));
}

function audit_rows(string $action): array
{
    return Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', $action)->get()->all());
}

function outbox_count(string $type): int
{
    return Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', $type)->count());
}

describe('issuing a key', function () {
    it('stores only the hash, returns the secret once and fixes the project key', function (string $type, string $prefix) {
        $issued = issue($this->p, ['type' => $type, 'scopes' => $type === 'management' ? ['flags:read'] : ['snapshot:read']]);
        $secret = $issued->secret->reveal();
        $row = Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId));
        $stored = (string) json_encode(Tenancy::crossTenant('inspect', fn () => DB::table('credentials')->get()->all()), JSON_INVALID_UTF8_SUBSTITUTE);

        expect($secret)->toStartWith($prefix)
            ->and($row->display_prefix)->toBe($prefix)
            ->and($row->last4)->toBe(substr($secret, -4))
            ->and($row->secret_hash)->toBe(app(SecretHasher::class)->hash($secret))
            ->and($row->created_by)->toBe($this->p['user']->id)
            ->and($row->status)->toBe(CredentialStatus::Active)
            ->and($stored)->not->toContain($secret)->not->toContain(substr($secret, -36, 30))
            ->and(Tenancy::crossTenant('inspect', fn () => Project::query()->findOrFail($this->p['project']->id)->key_locked_at))->not->toBeNull();
    })->with([
        'server' => ['server', 'bf_srv_prod_'],
        'browser' => ['browser', 'bf_pub_prod_'],
        'management' => ['management', 'bf_mgt_prod_'],
    ]);

    it('records the audit entry without the secret and sets the expiry', function () {
        $issued = issue($this->p, ['label' => ' Web ', 'scopes' => ['snapshot:read', 'events:write'], 'ttlDays' => 90]);
        $row = Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId));
        $audit = audit_rows('key.created');

        expect($row->label)->toBe('Web')
            ->and($row->ttl_days)->toBe(90)
            ->and($row->expires_at?->isFuture())->toBeTrue()
            ->and($audit)->toHaveCount(1)
            ->and($audit[0]->details['scopes'])->toBe(['snapshot:read', 'events:write'])
            ->and(json_encode($audit[0]->details))->not->toContain($issued->secret->reveal());
    });

    it('works with the key it issues', function () {
        $issued = issue($this->p);

        expect(app(CredentialResolver::class)->resolve($issued->secret->reveal())->id)->toBe($issued->credentialId);
    });

    it('is for people who manage credentials, with a confirmed email', function (?ProjectRole $role) {
        $p = person(OrgRole::Member, $role);

        expect(fn () => issue($p))->toThrow(AuthorizationDenied::class)
            ->and(Tenancy::crossTenant('inspect', fn () => Credential::query()->count()))->toBe(0);
    })->with([
        'publisher' => [ProjectRole::Publisher],
        'developer' => [ProjectRole::Developer],
        'viewer' => [ProjectRole::Viewer],
        'no role' => [null],
    ]);

    it('refuses an unverified email', function () {
        $this->p['user']->forceFill(['email_verified_at' => null])->save();

        expect(fn () => issue($this->p))->toThrow(EmailNotVerified::class);
    });

    it('refuses an environment of another organization', function () {
        $other = person(OrgRole::Member, ProjectRole::Owner);

        expect(fn () => issue($this->p, ['environmentId' => $other['environment']->id]))->toThrow(AuthorizationDenied::class);
    });

    it('refuses an archived environment and a read-only project', function () {
        Tenancy::crossTenant('inspect', fn () => $this->p['environment']->forceFill(['status' => 'archived'])->save());
        expect(fn () => issue($this->p))->toThrow(ValidationException::class);

        Tenancy::crossTenant('inspect', fn () => $this->p['environment']->forceFill(['status' => 'active'])->save());
        Tenancy::crossTenant('inspect', fn () => Project::query()->whereKey($this->p['project']->id)->update(['status' => 'archived']));
        expect(fn () => issue($this->p))->toThrow(ValidationException::class);
    });

    it('refuses values the form cannot send', function (array $change, string $field) {
        try {
            issue($this->p, $change);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }

        expect(Tenancy::crossTenant('inspect', fn () => Credential::query()->count()))->toBe(0);
    })->with([
        'no label' => [['label' => ' '], 'label'],
        'long label' => [['label' => str_repeat('a', 61)], 'label'],
        'personal type' => [['type' => 'personal'], 'type'],
        'unknown type' => [['type' => 'admin'], 'type'],
        'no scope' => [['scopes' => []], 'scopes'],
        'scope the type cannot have' => [['scopes' => ['publish']], 'scopes'],
        'browser with evaluate' => [['type' => 'browser', 'scopes' => ['evaluate']], 'scopes'],
        'expiry' => [['ttlDays' => 45], 'ttl_days'],
    ]);

    it('rolls back when the audit entry cannot be written', function () {
        app()->instance(AuditCatalog::class, new AuditCatalog);
        app()->forgetInstance(AuditRecorder::class);

        expect(fn () => issue($this->p))->toThrow(UnknownAuditAction::class)
            ->and(Tenancy::crossTenant('inspect', fn () => Credential::query()->count()))->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => Project::query()->findOrFail($this->p['project']->id)->key_locked_at))->toBeNull();
    });
});

describe('rotating a key', function () {
    function rotate(array $p, string $id, int $overlap = 86400, ?Actor $actor = null)
    {
        return app(RotateCredential::class)->handle($actor ?? $p['actor'], new RotateCredentialData($p['organization']->id, $p['project']->id, $p['environment']->id, $id, $overlap));
    }

    it('makes a replacement like the original and keeps the old one working for the overlap', function () {
        $old = issue($this->p, ['label' => 'API', 'scopes' => ['snapshot:read', 'events:write'], 'ttlDays' => 30]);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($old->secret->reveal());

        $rotated = rotate($this->p, $old->credentialId, 3600);

        $oldRow = credential_now(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($old->credentialId)));
        $newRow = Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($rotated->replacementId));

        expect($oldRow->status)->toBe(CredentialStatus::Rotating)
            ->and($oldRow->replaced_by_id)->toBe($newRow->id)
            ->and($newRow->rotated_from_id)->toBe($oldRow->id)
            ->and($newRow->label)->toBe('API')
            ->and($newRow->scopes)->toBe(['snapshot:read', 'events:write'])
            ->and($newRow->ttl_days)->toBe(30)
            ->and($newRow->display_prefix)->toBe($oldRow->display_prefix)
            ->and($rotated->secret->reveal())->not->toBe($old->secret->reveal())
            ->and($resolver->resolve($old->secret->reveal())->id)->toBe($oldRow->id)
            ->and($resolver->resolve($rotated->secret->reveal())->id)->toBe($newRow->id)
            ->and(audit_rows('key.rotated'))->toHaveCount(1)
            ->and(outbox_count('internal.key.rotated'))->toBe(1);
    });

    it('lets the old key work until the second the overlap ends, then rejects it', function () {
        $old = issue($this->p);
        rotate($this->p, $old->credentialId, 3600);
        $resolver = app(CredentialResolver::class);

        $this->travel(59)->minutes();
        $this->travel(59)->seconds();
        expect($resolver->resolve($old->secret->reveal())->status)->toBe(CredentialStatus::Rotating);

        $this->travel(1)->seconds();
        expect(fn () => $resolver->resolve($old->secret->reveal()))->toThrow(RevokedCredential::class);
    });

    it('revokes the old key when the overlap ends, once, and emits key.revoked once', function () {
        $old = issue($this->p);
        $rotated = rotate($this->p, $old->credentialId, 3600);

        $this->travel(61)->minutes();

        foreach (app(DueCredentials::class)->rotationsToComplete() as $row) {
            app(CompleteRotation::class)->handle($row['organizationId'], $row['credentialId']);
        }
        foreach (app(DueCredentials::class)->rotationsToComplete() as $row) {
            app(CompleteRotation::class)->handle($row['organizationId'], $row['credentialId']);
        }

        $row = credential_now(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($old->credentialId)));

        expect($row->status)->toBe(CredentialStatus::Revoked)
            ->and($row->revoke_reason?->value)->toBe('rotation_completed')
            ->and($row->revoked_by)->toBeNull()
            ->and(outbox_count('key.revoked'))->toBe(1)
            ->and(app(CredentialResolver::class)->resolve($rotated->secret->reveal())->status)->toBe(CredentialStatus::Active);
    });

    it('rotates only active keys and only in its environment, for people who manage credentials', function () {
        $old = issue($this->p);
        rotate($this->p, $old->credentialId);

        expect(fn () => rotate($this->p, $old->credentialId))->toThrow(ValidationException::class)
            ->and(fn () => rotate($this->p, $old->credentialId, 60))->toThrow(ValidationException::class);

        $viewer = person(OrgRole::Member, ProjectRole::Viewer);
        $key = make_credential($viewer);
        expect(fn () => rotate($viewer, $key['credential']->id))->toThrow(AuthorizationDenied::class);

        $other = person(OrgRole::Member, ProjectRole::Owner);
        $foreign = make_credential($other);
        expect(fn () => rotate($this->p, $foreign['credential']->id))->toThrow(ResourceNotFound::class);
    });

    it('is not blocked by a read-only plan', function () {
        $old = issue($this->p);
        app()->bind(PlanRestrictionGate::class, fn () => new class implements PlanRestrictionGate
        {
            public function assertConfigWritable(string $organizationId, string $projectId, ?string $environmentId = null): void
            {
                throw new RuntimeException('read-only');
            }
        });

        expect(rotate($this->p, $old->credentialId)->replacementId)->not->toBeEmpty();
    });
});

describe('revoking a key', function () {
    function revoke(array $p, string $id, ?Actor $actor = null): void
    {
        app(RevokeCredential::class)->handle($actor ?? $p['actor'], new RevokeCredentialData($p['organization']->id, $id));
    }

    it('rejects the key on the very next request even with a warm cache', function () {
        $issued = issue($this->p);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($issued->secret->reveal());
        $resolver->resolve($issued->secret->reveal());

        revoke($this->p, $issued->credentialId);

        expect(fn () => $resolver->resolve($issued->secret->reveal()))->toThrow(RevokedCredential::class);
    });

    it('records who revoked it, the audit entry and both outbox messages', function () {
        $issued = issue($this->p);

        revoke($this->p, $issued->credentialId);

        $row = Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId));

        expect($row->status)->toBe(CredentialStatus::Revoked)
            ->and($row->revoked_by)->toBe($this->p['user']->id)
            ->and($row->revoke_reason?->value)->toBe('manual')
            ->and(audit_rows('key.revoked'))->toHaveCount(1)
            ->and(outbox_count('key.revoked'))->toBe(1)
            ->and(outbox_count('internal.credential.state_changed'))->toBe(1);
    });

    it('does nothing the second time', function () {
        $issued = issue($this->p);

        revoke($this->p, $issued->credentialId);
        revoke($this->p, $issued->credentialId);

        expect(audit_rows('key.revoked'))->toHaveCount(1)->and(outbox_count('key.revoked'))->toBe(1);
    });

    it('works on a rotating key', function () {
        $issued = issue($this->p);
        rotate($this->p, $issued->credentialId);

        revoke($this->p, $issued->credentialId);

        expect(fn () => app(CredentialResolver::class)->resolve($issued->secret->reveal()))->toThrow(RevokedCredential::class);
    });

    it('is for people who manage credentials', function (?ProjectRole $role) {
        $viewer = person(OrgRole::Member, $role);
        $key = make_credential($viewer);

        expect(fn () => revoke($viewer, $key['credential']->id))->toThrow(AuthorizationDenied::class)
            ->and(credential_now($key['credential'])->status)->toBe(CredentialStatus::Active);
    })->with([
        'publisher' => [ProjectRole::Publisher],
        'developer' => [ProjectRole::Developer],
        'viewer' => [ProjectRole::Viewer],
    ]);

    it('does not reach a credential of another organization', function () {
        $other = person(OrgRole::Member, ProjectRole::Owner);
        $key = make_credential($other);

        expect(fn () => revoke($this->p, $key['credential']->id))->toThrow(ResourceNotFound::class)
            ->and(credential_now($key['credential'])->status)->toBe(CredentialStatus::Active);
    });

    it('is allowed in a read-only project and under a read-only plan', function () {
        $issued = issue($this->p);
        Tenancy::crossTenant('inspect', fn () => Project::query()->whereKey($this->p['project']->id)->update(['status' => 'archived']));

        revoke($this->p, $issued->credentialId);

        expect(credential_now(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId)))->status)->toBe(CredentialStatus::Revoked);
    });

    it('rolls back when the audit entry cannot be written', function () {
        $issued = issue($this->p);
        app()->instance(AuditCatalog::class, new AuditCatalog);
        app()->forgetInstance(AuditRecorder::class);

        expect(fn () => revoke($this->p, $issued->credentialId))->toThrow(UnknownAuditAction::class)
            ->and(credential_now(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId)))->status)->toBe(CredentialStatus::Active);
    });
});

describe('expiry', function () {
    it('rejects an expired key at the expiry time, and the job then marks it expired once', function () {
        $issued = issue($this->p, ['ttlDays' => 30]);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($issued->secret->reveal());

        $this->travel(31)->days();
        expect(fn () => $resolver->resolve($issued->secret->reveal()))->toThrow(RevokedCredential::class);

        foreach (app(DueCredentials::class)->toExpire() as $row) {
            app(ExpireCredential::class)->handle($row['organizationId'], $row['credentialId']);
        }
        foreach (app(DueCredentials::class)->toExpire() as $row) {
            app(ExpireCredential::class)->handle($row['organizationId'], $row['credentialId']);
        }

        expect(credential_now(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId)))->status)->toBe(CredentialStatus::Expired)
            ->and(audit_rows('key.expired'))->toHaveCount(1);
    });

    it('does not expire a key before its time', function () {
        $issued = issue($this->p, ['ttlDays' => 30]);

        app(ExpireCredential::class)->handle($this->p['organization']->id, $issued->credentialId);

        expect(credential_now(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId)))->status)->toBe(CredentialStatus::Active);
    });
});
