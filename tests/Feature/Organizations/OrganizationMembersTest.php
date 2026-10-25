<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\AddSelfToProject;
use App\Modules\Organizations\Actions\ChangeOrganizationRole;
use App\Modules\Organizations\Actions\RemoveOrganizationMember;
use App\Modules\Organizations\Data\ChangeOrganizationRoleData;
use App\Modules\Organizations\Data\RemoveOrganizationMemberData;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Events\MemberRemovedFromOrganization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->owner = person(OrgRole::Owner, ProjectRole::Owner);
});

/** Adds a person to the organization of $this->owner. */
function add_member(array $owner, OrgRole $role, ?ProjectRole $project = null): array
{
    $user = User::factory()->create();
    $membership = OrganizationMembership::factory()->create(['organization_id' => $owner['organization']->id, 'user_id' => $user->id, 'role' => $role]);

    if ($project !== null) {
        ProjectMembership::factory()->create(['organization_id' => $owner['organization']->id, 'project_id' => $owner['project']->id, 'user_id' => $user->id, 'role' => $project]);
    }

    return ['user' => $user, 'membership' => $membership];
}

function owner_membership(array $p): OrganizationMembership
{
    return Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('organization_id', $p['organization']->id)->where('user_id', $p['user']->id)->firstOrFail());
}

function role_change(array $by, string $membershipId, OrgRole $role): void
{
    app(ChangeOrganizationRole::class)->handle($by['actor'], new ChangeOrganizationRoleData($by['organization']->id, $membershipId, $role));
}

describe('changing a role', function () {
    it('makes a Member an Org Owner and back, recording before and after', function () {
        $other = add_member($this->owner, OrgRole::Member);

        role_change($this->owner, $other['membership']->id, OrgRole::Owner);
        expect(Tenancy::crossTenant('inspect', fn () => $other['membership']->fresh()->role))->toBe(OrgRole::Owner);

        role_change($this->owner, $other['membership']->id, OrgRole::Member);

        $events = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'org_member.role_changed')->orderBy('id')->get());
        expect($events)->toHaveCount(2)
            ->and($events[0]->details)->toMatchArray(['before' => 'member', 'after' => 'owner'])
            ->and($events[1]->details)->toMatchArray(['before' => 'owner', 'after' => 'member']);
    });

    it('does nothing, and records nothing, when the role does not change', function () {
        $other = add_member($this->owner, OrgRole::Member);

        role_change($this->owner, $other['membership']->id, OrgRole::Member);

        expect(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'org_member.role_changed')->count()))->toBe(0);
    });

    it('keeps one Org Owner when two owners demote each other at the same moment', function () {
        $first = $this->owner;
        $second = add_member($first, OrgRole::Owner);
        $secondActor = Actor::user($second['user']->id, 'Second', Channel::Dashboard);
        $organization = new OrganizationScope($first['organization']->id);

        // The second owner's request was authorized while they were still an owner.
        expect($secondActor->can(Capability::OrgMembersManage, $organization))->toBeTrue();

        // The first owner's request commits first and demotes the second.
        role_change($first, $second['membership']->id, OrgRole::Member);

        // The second request now runs against the new state: the first is the only owner and cannot be demoted.
        expect(fn () => app(ChangeOrganizationRole::class)->handle($secondActor, new ChangeOrganizationRoleData($first['organization']->id, owner_membership($first)->id, OrgRole::Member)))
            ->toThrow(LastOwner::class);
        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('role', 'owner')->count()))->toBe(1);
    });

    it('refuses to change your own role', function () {
        expect(fn () => role_change($this->owner, owner_membership($this->owner)->id, OrgRole::Member))
            ->toThrow(ValidationException::class, 'own role');
    });

    it('is for people who manage the members', function () {
        $member = add_member($this->owner, OrgRole::Member);
        $other = add_member($this->owner, OrgRole::Member);
        $actor = Actor::user($member['user']->id, 'M', Channel::Dashboard);

        expect(fn () => app(ChangeOrganizationRole::class)->handle($actor, new ChangeOrganizationRoleData($this->owner['organization']->id, $other['membership']->id, OrgRole::Owner)))
            ->toThrow(AuthorizationDenied::class);
    });

    it('cannot reach a membership of another organization', function () {
        $theirs = person(OrgRole::Owner, ProjectRole::Owner);
        $victim = add_member($theirs, OrgRole::Member);

        expect(fn () => role_change($this->owner, $victim['membership']->id, OrgRole::Owner))->toThrow(ModelNotFoundException::class);
        expect(Tenancy::crossTenant('inspect', fn () => $victim['membership']->fresh()->role))->toBe(OrgRole::Member);
    });
});

describe('removing a member', function () {
    function remove_member(array $by, string $membershipId): void
    {
        app(RemoveOrganizationMember::class)->handle($by['actor'], new RemoveOrganizationMemberData($by['organization']->id, $membershipId));
    }

    it('removes them from the organization and from every project in it, and announces it', function () {
        Event::fake([MemberRemovedFromOrganization::class]);
        $other = add_member($this->owner, OrgRole::Member, ProjectRole::Developer);

        remove_member($this->owner, $other['membership']->id);

        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $other['user']->id)->count()))->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $other['user']->id)->count()))->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'org_member.removed')->count()))->toBe(1);
        Event::assertDispatched(MemberRemovedFromOrganization::class, fn ($e) => $e->userId === $other['user']->id && $e->organizationId === $this->owner['organization']->id);
    });

    it('leaves their membership of other organizations alone', function () {
        $other = add_member($this->owner, OrgRole::Member, ProjectRole::Developer);
        $elsewhere = person(OrgRole::Member, ProjectRole::Developer);
        OrganizationMembership::factory()->create(['organization_id' => $elsewhere['organization']->id, 'user_id' => $other['user']->id, 'role' => OrgRole::Member]);

        remove_member($this->owner, $other['membership']->id);

        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $other['user']->id)->count()))->toBe(1);
    });

    it('refuses to remove yourself', function () {
        expect(fn () => remove_member($this->owner, owner_membership($this->owner)->id))->toThrow(ValidationException::class, 'yourself');
    });

    it('keeps one Org Owner when two owners remove each other at the same moment', function () {
        $first = $this->owner;
        $second = add_member($first, OrgRole::Owner);
        $secondActor = Actor::user($second['user']->id, 'Second', Channel::Dashboard);
        expect($secondActor->can(Capability::OrgMembersManage, new OrganizationScope($first['organization']->id)))->toBeTrue();

        remove_member($first, $second['membership']->id);

        expect(fn () => app(RemoveOrganizationMember::class)->handle($secondActor, new RemoveOrganizationMemberData($first['organization']->id, owner_membership($first)->id)))
            ->toThrow(LastOwner::class);
        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('role', 'owner')->count()))->toBe(1);
    });

    it('does not announce a removal that failed', function () {
        Event::fake([MemberRemovedFromOrganization::class]);

        try {
            remove_member($this->owner, owner_membership($this->owner)->id);
        } catch (ValidationException) {
        }

        Event::assertNotDispatched(MemberRemovedFromOrganization::class);
    });

    it('is for people who manage the members', function () {
        $member = add_member($this->owner, OrgRole::Member);
        $other = add_member($this->owner, OrgRole::Member);
        $actor = Actor::user($member['user']->id, 'M', Channel::Dashboard);

        expect(fn () => app(RemoveOrganizationMember::class)->handle($actor, new RemoveOrganizationMemberData($this->owner['organization']->id, $other['membership']->id)))
            ->toThrow(AuthorizationDenied::class);
    });
});

describe('an Org Owner joining a project', function () {
    it('joins any project as its Owner, and it is audited', function () {
        $owner = person(OrgRole::Owner, null);
        $project = Project::factory()->create(['organization_id' => $owner['organization']->id]);

        app(AddSelfToProject::class)->handle($owner['actor'], $owner['organization']->id, $project->id);

        $membership = Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('project_id', $project->id)->where('user_id', $owner['user']->id)->firstOrFail());

        expect($membership->role)->toBe(ProjectRole::Owner)
            ->and($membership->added_by)->toBe($owner['user']->id)
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'project_member.self_added')->count()))->toBe(1);
    });

    it('is for Org Owners, for projects of their own organization, and only once', function () {
        $owner = person(OrgRole::Owner, null);
        $mine = Project::factory()->create(['organization_id' => $owner['organization']->id]);
        $theirs = person(OrgRole::Owner, ProjectRole::Owner);
        $member = person(OrgRole::Member, null);
        $memberProject = Project::factory()->create(['organization_id' => $member['organization']->id]);

        expect(fn () => app(AddSelfToProject::class)->handle($member['actor'], $member['organization']->id, $memberProject->id))->toThrow(AuthorizationDenied::class);
        expect(fn () => app(AddSelfToProject::class)->handle($owner['actor'], $owner['organization']->id, $theirs['project']->id))->toThrow(ResourceNotFound::class);

        app(AddSelfToProject::class)->handle($owner['actor'], $owner['organization']->id, $mine->id);
        expect(fn () => app(AddSelfToProject::class)->handle($owner['actor'], $owner['organization']->id, $mine->id))->toThrow(ValidationException::class);
    });

    it('does not join an archived project', function () {
        $owner = person(OrgRole::Owner, null);
        $archived = Project::factory()->create(['organization_id' => $owner['organization']->id, 'status' => 'archived']);

        expect(fn () => app(AddSelfToProject::class)->handle($owner['actor'], $owner['organization']->id, $archived->id))->toThrow(ResourceNotFound::class);
    });
});
