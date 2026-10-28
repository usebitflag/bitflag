<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\MarkEmailVerified;
use App\Modules\Identity\Data\MarkEmailVerifiedData;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\InviteMembers;
use App\Modules\Organizations\Actions\RevokeInvitation;
use App\Modules\Organizations\Data\ChangeInvitationData;
use App\Modules\Organizations\Data\InviteGrant;
use App\Modules\Organizations\Data\InviteMembersData;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\InvitationProjectGrant;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->owner = person(OrgRole::Owner, ProjectRole::Owner);
});

function invitations_of(array $p): Collection
{
    return Tenancy::crossTenant('inspect', fn () => Invitation::query()->where('organization_id', $p['organization']->id)->orderBy('id')->get());
}

function email_requests(): int
{
    return OutboxMessageRecord::query()->where('payload->template', 'invitation')->count();
}

describe('inviting people', function () {
    it('creates a pending invitation with the projects and role, and asks for the email', function () {
        $result = invite_people($this->owner, ['Dev@Northwind.Studio']);

        $invitation = invitations_of($this->owner)->firstOrFail();
        $grant = Tenancy::crossTenant('inspect', fn () => InvitationProjectGrant::query()->firstOrFail());

        expect($result->rejected)->toBe([])
            ->and($result->created)->toHaveCount(1)
            ->and($result->created[0]['queued'])->toBeFalse()
            ->and($invitation->email)->toBe('dev@northwind.studio')
            ->and($invitation->status->value)->toBe('pending')
            ->and($invitation->org_role)->toBe(OrgRole::Member)
            ->and($invitation->invited_by)->toBe($this->owner['user']->id)
            ->and($invitation->token_hash)->toBeNull()
            ->and($grant->project_id)->toBe($this->owner['project']->id)
            ->and($grant->role)->toBe(ProjectRole::Developer)
            ->and(email_requests())->toBe(1);
    });

    it('asks for the email without putting the address or a link in the message', function () {
        invite_people($this->owner, ['dev@northwind.studio']);

        $record = OutboxMessageRecord::query()->where('payload->template', 'invitation')->firstOrFail();

        expect($record->scope->value)->toBe('organization')
            ->and($record->organization_id)->toBe($this->owner['organization']->id)
            ->and(json_encode($record->payload))->not->toContain('northwind')->not->toContain('http');
    });

    it('records each invitation in the audit trail', function () {
        invite_people($this->owner, ['a@northwind.studio', 'b@northwind.studio']);

        $events = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'invitation.created')->get());

        expect($events)->toHaveCount(2)
            ->and($events->pluck('target_display')->sort()->values()->all())->toBe(['a@northwind.studio', 'b@northwind.studio']);
    });

    it('can invite an Org Owner with no projects', function () {
        invite_people($this->owner, ['boss@northwind.studio'], OrgRole::Owner, []);

        $invitation = invitations_of($this->owner)->firstOrFail();

        expect($invitation->org_role)->toBe(OrgRole::Owner);
        expect(Tenancy::crossTenant('inspect', fn () => InvitationProjectGrant::query()->count()))->toBe(0);
    });

    it('queues invitations until the inviter confirms their email, then sends them', function () {
        $this->owner['user']->forceFill(['email_verified_at' => null])->save();

        $result = invite_people($this->owner, ['dev@northwind.studio']);

        expect($result->created[0]['queued'])->toBeTrue()
            ->and(invitations_of($this->owner)->first()->status->value)->toBe('queued')
            ->and(email_requests())->toBe(0);

        $user = $this->owner['user']->fresh();
        app(MarkEmailVerified::class)->handle(new MarkEmailVerifiedData($user->id, sha1($user->email), $user->id));

        expect(invitations_of($this->owner)->first()->status->value)->toBe('pending')
            ->and(email_requests())->toBe(1);
    });

    it('does not send an invitation that was withdrawn while it waited', function () {
        $this->owner['user']->forceFill(['email_verified_at' => null])->save();
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        app(RevokeInvitation::class)->handle($this->owner['actor'], new ChangeInvitationData($this->owner['organization']->id, $id));

        $user = $this->owner['user']->fresh();
        app(MarkEmailVerified::class)->handle(new MarkEmailVerifiedData($user->id, sha1($user->email), $user->id));

        expect(invitation_status($id))->toBe('revoked')->and(email_requests())->toBe(0);
    });

    it('releases only the invitations of the person who confirmed', function () {
        $this->owner['user']->forceFill(['email_verified_at' => null])->save();
        $other = person(OrgRole::Owner, ProjectRole::Owner);
        $other['user']->forceFill(['email_verified_at' => null])->save();
        invite_people($this->owner, ['a@northwind.studio']);
        invite_people($other, ['b@northwind.studio']);

        $user = $this->owner['user']->fresh();
        app(MarkEmailVerified::class)->handle(new MarkEmailVerifiedData($user->id, sha1($user->email), $user->id));

        expect(invitations_of($this->owner)->first()->status->value)->toBe('pending')
            ->and(invitations_of($other)->first()->status->value)->toBe('queued');
    });
});

describe('addresses that cannot be invited', function () {
    it('turns down a bad address and invites the rest', function () {
        $result = invite_people($this->owner, ['not-an-email', 'good@northwind.studio']);

        expect($result->rejected)->toBe(['not-an-email' => 'Not a valid email address'])
            ->and($result->created)->toHaveCount(1);
    });

    it('turns down an address that already has an open invitation, however it is written', function () {
        invite_people($this->owner, ['dev@northwind.studio']);

        $result = invite_people($this->owner, ['DEV@northwind.studio']);

        expect($result->rejected)->toBe(['dev@northwind.studio' => 'Already invited'])
            ->and(invitations_of($this->owner))->toHaveCount(1);
    });

    it('allows a new invitation after the old one was withdrawn or used', function () {
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        app(RevokeInvitation::class)->handle($this->owner['actor'], new ChangeInvitationData($this->owner['organization']->id, $id));

        expect(invite_people($this->owner, ['dev@northwind.studio'])->created)->toHaveCount(1);
    });

    it('turns down someone who already belongs, with every project invited to', function () {
        $member = verified_user('maya@northwind.studio');
        OrganizationMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'user_id' => $member->id, 'role' => OrgRole::Member]);
        ProjectMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'project_id' => $this->owner['project']->id, 'user_id' => $member->id, 'role' => ProjectRole::Viewer]);

        $result = invite_people($this->owner, ['maya@northwind.studio']);

        expect($result->rejected)->toBe(['maya@northwind.studio' => 'Already a member']);
    });

    it('invites a member to a project they are not in yet', function () {
        $member = verified_user('maya@northwind.studio');
        OrganizationMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'user_id' => $member->id, 'role' => OrgRole::Member]);

        expect(invite_people($this->owner, ['maya@northwind.studio'])->created)->toHaveCount(1);
    });

    it('invites a Member to be an Org Owner', function () {
        $member = verified_user('maya@northwind.studio');
        OrganizationMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'user_id' => $member->id, 'role' => OrgRole::Member]);

        expect(invite_people($this->owner, ['maya@northwind.studio'], OrgRole::Owner, [])->created)->toHaveCount(1);
    });

    it('turns down addresses outside the allowed domains', function () {
        Organization::query()->whereKey($this->owner['organization']->id)->update(['allowed_email_domains' => json_encode(['northwind.studio'])]);

        $result = invite_people($this->owner, ['ok@northwind.studio', 'no@elsewhere.org']);

        expect($result->rejected)->toBe(['no@elsewhere.org' => 'Invitations to other domains are blocked'])
            ->and($result->created)->toHaveCount(1);
    });

    it('allows up to 200 invitations a day for an organization', function () {
        $now = now();
        $rows = array_map(fn ($n) => [
            'id' => strtolower((string) Str::ulid()), 'organization_id' => $this->owner['organization']->id, 'email' => "old{$n}@x.dev",
            'org_role' => 'member', 'status' => 'revoked', 'invited_by' => $this->owner['user']->id, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 199));
        DB::table('invitations')->insert($rows);

        $result = invite_people($this->owner, ['one@northwind.studio', 'two@northwind.studio']);

        expect($result->created)->toHaveCount(1)
            ->and($result->rejected)->toBe(['two@northwind.studio' => 'Too many invitations today']);
    });

    it('does not count invitations older than a day', function () {
        $old = now()->subDays(2);
        DB::table('invitations')->insert(array_map(fn ($n) => [
            'id' => strtolower((string) Str::ulid()), 'organization_id' => $this->owner['organization']->id, 'email' => "old{$n}@x.dev",
            'org_role' => 'member', 'status' => 'revoked', 'invited_by' => $this->owner['user']->id, 'created_at' => $old, 'updated_at' => $old,
        ], range(1, 200)));

        expect(invite_people($this->owner, ['new@northwind.studio'])->created)->toHaveCount(1);
    });

    it('refuses an empty list, and more than 20 addresses', function () {
        expect(fn () => invite_people($this->owner, []))->toThrow(ValidationException::class);
        expect(fn () => invite_people($this->owner, array_map(fn ($n) => "p{$n}@northwind.studio", range(1, 21))))->toThrow(ValidationException::class, 'up to 20');
        expect(invite_people($this->owner, array_map(fn ($n) => "p{$n}@northwind.studio", range(1, 20)))->created)->toHaveCount(20);
    });

    it('invites a repeated address once', function () {
        $result = invite_people($this->owner, ['a@northwind.studio', 'A@northwind.studio']);

        expect($result->created)->toHaveCount(1);
    });
});

describe('who may invite', function () {
    it('refuses an organization Member', function () {
        $member = person(OrgRole::Member, ProjectRole::Developer);

        expect(fn () => invite_people($member, ['x@northwind.studio']))->toThrow(AuthorizationDenied::class);
    });

    it('refuses someone from another organization', function () {
        $stranger = person(OrgRole::Owner, ProjectRole::Owner);

        expect(fn () => app(InviteMembers::class)->handle($stranger['actor'], new InviteMembersData($this->owner['organization']->id, ['x@northwind.studio'], OrgRole::Member, [])))
            ->toThrow(AuthorizationDenied::class);
    });

    it('lets a project Owner invite people to that project as organization Members', function () {
        $projectOwner = person(OrgRole::Member, ProjectRole::Owner);

        $result = invite_people($projectOwner, ['dev@northwind.studio']);

        expect($result->created)->toHaveCount(1);
    });

    it('does not let a project Owner invite an Org Owner, nobody to no project, or someone to another project', function () {
        $projectOwner = person(OrgRole::Member, ProjectRole::Owner);
        $otherProject = Project::factory()->create(['organization_id' => $projectOwner['organization']->id]);

        expect(fn () => invite_people($projectOwner, ['x@northwind.studio'], OrgRole::Owner))->toThrow(AuthorizationDenied::class);
        expect(fn () => invite_people($projectOwner, ['x@northwind.studio'], OrgRole::Member, []))->toThrow(AuthorizationDenied::class);
        expect(fn () => invite_people($projectOwner, ['x@northwind.studio'], OrgRole::Member, [new InviteGrant($otherProject->id, ProjectRole::Viewer)]))->toThrow(AuthorizationDenied::class);
    });

    it('does not let a project Publisher or Developer invite', function (ProjectRole $role) {
        $p = person(OrgRole::Member, $role);

        expect(fn () => invite_people($p, ['x@northwind.studio']))->toThrow(AuthorizationDenied::class);
    })->with([ProjectRole::Publisher, ProjectRole::Developer, ProjectRole::Viewer]);

    it('refuses a project of another organization, an archived one, or the same one twice', function () {
        $foreign = person(OrgRole::Owner, ProjectRole::Owner);
        $archived = Project::factory()->create(['organization_id' => $this->owner['organization']->id, 'status' => 'archived']);

        expect(fn () => invite_people($this->owner, ['x@northwind.studio'], OrgRole::Member, [new InviteGrant($foreign['project']->id, ProjectRole::Viewer)]))->toThrow(ValidationException::class);
        expect(fn () => invite_people($this->owner, ['x@northwind.studio'], OrgRole::Member, [new InviteGrant($archived->id, ProjectRole::Viewer)]))->toThrow(ValidationException::class);
        expect(fn () => invite_people($this->owner, ['x@northwind.studio'], OrgRole::Member, [new InviteGrant($this->owner['project']->id, ProjectRole::Viewer), new InviteGrant($this->owner['project']->id, ProjectRole::Owner)]))->toThrow(ValidationException::class);
    });

    it('is not available to a system actor', function () {
        expect(fn () => app(InviteMembers::class)->handle(Actor::system('test'), new InviteMembersData($this->owner['organization']->id, ['x@northwind.studio'], OrgRole::Member, [])))
            ->toThrow(AuthorizationDenied::class);
    });
});

it('leaves nothing behind when a later write fails', function () {
    app()->bind(Outbox::class, fn () => throw new RuntimeException('outbox down'));

    expect(fn () => invite_people($this->owner, ['dev@northwind.studio']))->toThrow(RuntimeException::class);
    expect(invitations_of($this->owner))->toHaveCount(0);
});

it('isolates invitations by organization', function () {
    assertTenantIsolated(Invitation::class, fn (Organization $organization) => Invitation::query()->create([
        'organization_id' => $organization->id, 'email' => 'x@y.dev', 'org_role' => OrgRole::Member, 'status' => 'pending', 'invited_by' => User::factory()->create()->id,
    ]));
});
