<?php

declare(strict_types=1);

use App\Modules\Credentials\Actions\IssuePersonalAccessToken;
use App\Modules\Credentials\Actions\NotifyExpiringCredentials;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Data\IssuePersonalAccessTokenData;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Credentials\Queries\DueCredentials;
use App\Modules\Identity\Actions\DeleteAccount;
use App\Modules\Identity\Data\DeleteAccountData;
use App\Modules\Organizations\Actions\RemoveProjectMember;
use App\Modules\Organizations\Data\RemoveProjectMemberData;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\EmailNotVerified;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Redis::flushdb());

function token_data(array $p, array $change = []): IssuePersonalAccessTokenData
{
    return new IssuePersonalAccessTokenData(...[
        'organizationId' => $p['organization']->id,
        'projectId' => $p['project']->id,
        'label' => 'Laptop CLI',
        'accessLevel' => 'draft',
        'ttlDays' => 90,
        ...$change,
    ]);
}

function issue_token(array $p, array $change = [], ?Actor $actor = null)
{
    return app(IssuePersonalAccessToken::class)->handle($actor ?? $p['actor'], token_data($p, $change));
}

describe('making a personal token', function () {
    it('binds it to the person and the project, with the scopes of the access level', function (string $level, array $scopes) {
        $p = person(OrgRole::Member, ProjectRole::Publisher);

        $issued = issue_token($p, ['accessLevel' => $level]);
        $row = Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($issued->credentialId));

        expect($row->type)->toBe(CredentialType::Personal)
            ->and($row->user_id)->toBe($p['user']->id)
            ->and($row->project_id)->toBe($p['project']->id)
            ->and($row->environment_id)->toBeNull()
            ->and($row->scopes)->toBe($scopes)
            ->and($row->display_prefix)->toBe('bf_pat_')
            ->and($row->expires_at?->isFuture())->toBeTrue()
            ->and($issued->secret->reveal())->toStartWith('bf_pat_')
            ->and(app(CredentialResolver::class)->resolve($issued->secret->reveal())->userId)->toBe($p['user']->id)
            ->and(Tenancy::crossTenant('inspect', fn () => Project::query()->findOrFail($p['project']->id)->key_locked_at))->not->toBeNull();
    })->with([
        'read' => ['read', ['flags:read']],
        'draft' => ['draft', ['flags:read', 'flags:write']],
        'publish' => ['publish', ['flags:read', 'flags:write', 'publish']],
    ]);

    it('records the audit entry without the secret', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);

        $issued = issue_token($p);
        $audit = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'token.created')->get()->all());

        expect($audit)->toHaveCount(1)->and(json_encode($audit[0]->details))->not->toContain($issued->secret->reveal());
    });

    it('limits the access level to what the role allows', function (ProjectRole $role, string $level, bool $allowed) {
        $p = person(OrgRole::Member, $role);

        if ($allowed) {
            expect(issue_token($p, ['accessLevel' => $level])->credentialId)->not->toBeEmpty();
        } else {
            expect(fn () => issue_token($p, ['accessLevel' => $level]))->toThrow(ValidationException::class);
        }
    })->with([
        'viewer reads' => [ProjectRole::Viewer, 'read', true],
        'viewer cannot edit drafts' => [ProjectRole::Viewer, 'draft', false],
        'viewer cannot publish' => [ProjectRole::Viewer, 'publish', false],
        'developer edits drafts' => [ProjectRole::Developer, 'draft', true],
        'developer may publish (unprotected environments)' => [ProjectRole::Developer, 'publish', true],
        'publisher publishes' => [ProjectRole::Publisher, 'publish', true],
        'owner publishes' => [ProjectRole::Owner, 'publish', true],
    ]);

    it('is for members of the project with a confirmed email', function () {
        $stranger = person(OrgRole::Member, null);
        expect(fn () => issue_token($stranger))->toThrow(AuthorizationDenied::class);

        $other = person(OrgRole::Member, ProjectRole::Owner);
        expect(fn () => issue_token($other, ['projectId' => $stranger['project']->id], $other['actor']))->toThrow(AuthorizationDenied::class);

        $unverified = person(OrgRole::Member, ProjectRole::Owner);
        $unverified['user']->forceFill(['email_verified_at' => null])->save();
        expect(fn () => issue_token($unverified))->toThrow(EmailNotVerified::class);
    });

    it('cannot be made by a token', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $viaToken = Actor::personalToken($p['user']->id, $p['user']->name, 'tok', Channel::Api);

        expect(fn () => issue_token($p, [], $viaToken))->toThrow(AuthorizationDenied::class);
    });

    it('refuses bad values and a read-only project', function (array $change, string $field) {
        $p = person(OrgRole::Member, ProjectRole::Owner);

        try {
            issue_token($p, $change);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    })->with([
        'no name' => [['label' => ' '], 'label'],
        'long name' => [['label' => str_repeat('a', 61)], 'label'],
        'unknown level' => [['accessLevel' => 'admin'], 'access'],
        'expiry' => [['ttlDays' => 7], 'ttl_days'],
    ]);

    it('allows 20 active tokens per person and project, and counts only working ones', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);

        $first = issue_token($p);
        for ($i = 1; $i < 20; $i++) {
            issue_token($p);
        }

        expect(fn () => issue_token($p))->toThrow(ValidationException::class);

        app(RevokeCredential::class)->handle($p['actor'], new RevokeCredentialData($p['organization']->id, $first->credentialId));
        expect(issue_token($p)->credentialId)->not->toBeEmpty();
    });

    it('rolls back when the audit entry cannot be written', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        app()->instance(AuditCatalog::class, new AuditCatalog);
        app()->forgetInstance(AuditRecorder::class);

        expect(fn () => issue_token($p))->toThrow(UnknownAuditAction::class)
            ->and(Tenancy::crossTenant('inspect', fn () => Credential::query()->count()))->toBe(0);
    });
});

describe('revoking a personal token', function () {
    it('can be done by its owner or by someone who manages credentials, not by others', function () {
        $p = person(OrgRole::Member, ProjectRole::Developer);
        $token = issue_token($p);

        $other = person(OrgRole::Member, ProjectRole::Developer);
        expect(fn () => app(RevokeCredential::class)->handle($other['actor'], new RevokeCredentialData($p['organization']->id, $token->credentialId)))->toThrow(AuthorizationDenied::class);

        $manager = Actor::user($p['user']->id, 'x', Channel::Dashboard);
        app(RevokeCredential::class)->handle($manager, new RevokeCredentialData($p['organization']->id, $token->credentialId));

        expect(fn () => app(CredentialResolver::class)->resolve($token->secret->reveal()))->toThrow(RevokedCredential::class)
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'token.revoked')->count()))->toBe(1)
            ->and(Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', 'key.revoked')->count()))->toBe(0);
    });

    it('cannot be done by the token itself', function () {
        $p = person(OrgRole::Member, ProjectRole::Developer);
        $token = issue_token($p);
        $viaToken = Actor::personalToken($p['user']->id, $p['user']->name, $token->credentialId, Channel::Api);

        expect(fn () => app(RevokeCredential::class)->handle($viaToken, new RevokeCredentialData($p['organization']->id, $token->credentialId)))->toThrow(AuthorizationDenied::class);
    });
});

describe('when the person leaves', function () {
    it('revokes their tokens for the project, not other projects, and leaves management keys alone', function () {
        $p = person(OrgRole::Member, ProjectRole::Developer);
        $owner = person(OrgRole::Member, ProjectRole::Owner);
        $mine = issue_token($p);
        $management = make_credential($p, [], CredentialType::Management);

        $otherProject = Project::factory()->create(['organization_id' => $p['organization']->id]);
        ProjectMembership::factory()->create(['organization_id' => $p['organization']->id, 'project_id' => $otherProject->id, 'user_id' => $p['user']->id, 'role' => ProjectRole::Developer]);
        $elsewhere = issue_token($p, ['projectId' => $otherProject->id]);

        // Make the first project's Owner a member of the same organization so they can remove the developer.
        ProjectMembership::factory()->create(['organization_id' => $p['organization']->id, 'project_id' => $p['project']->id, 'user_id' => $owner['user']->id, 'role' => ProjectRole::Owner]);
        $membership = Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $p['user']->id)->where('project_id', $p['project']->id)->firstOrFail());

        app(RemoveProjectMember::class)->handle($owner['actor'], new RemoveProjectMemberData($p['organization']->id, $p['project']->id, $membership->id));

        expect(fn () => app(CredentialResolver::class)->resolve($mine->secret->reveal()))->toThrow(RevokedCredential::class)
            ->and(credential_now($management['credential'])->status)->toBe(CredentialStatus::Active)
            ->and(app(CredentialResolver::class)->resolve($elsewhere->secret->reveal())->status)->toBe(CredentialStatus::Active)
            ->and(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($mine->credentialId))->revoke_reason?->value)->toBe('member_removed');
    });

    it('revokes every token when the account is deleted', function () {
        $p = person(OrgRole::Owner, ProjectRole::Owner);
        $second = person(OrgRole::Owner, ProjectRole::Owner);
        $token = issue_token($p);
        // A second owner for the first organization, so deleting the account is allowed.
        OrganizationMembership::factory()->create(['organization_id' => $p['organization']->id, 'user_id' => $second['user']->id, 'role' => OrgRole::Owner]);

        app(DeleteAccount::class)->handle(new DeleteAccountData($p['user']->id, 'password', $p['user']->email, null, null));

        expect(fn () => app(CredentialResolver::class)->resolve($token->secret->reveal()))->toThrow(RevokedCredential::class)
            ->and(Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($token->credentialId))->revoke_reason?->value)->toBe('account_deleted');
    });
});

describe('expiring credentials', function () {
    it('announces a credential expiring within a week once, and nothing else', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $soon = issue_token($p, ['ttlDays' => 30]);
        Tenancy::crossTenant('inspect', fn () => Credential::query()->whereKey($soon->credentialId)->update(['expires_at' => now()->addDays(5)]));
        issue_token($p, ['ttlDays' => 365]);
        issue_token($p, ['ttlDays' => null]);

        $announce = function (): int {
            $count = 0;
            foreach (app(DueCredentials::class)->expiringWithin(7) as $row) {
                $count += app(NotifyExpiringCredentials::class)->handle($row['organizationId'], $row['credentialId']);
            }

            return $count;
        };

        expect($announce())->toBe(1)
            ->and($announce())->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', 'internal.credential.expiring')->count()))->toBe(1);
    });
});
