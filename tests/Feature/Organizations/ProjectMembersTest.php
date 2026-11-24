<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\ChangeProjectRole;
use App\Modules\Organizations\Actions\RemoveProjectMember;
use App\Modules\Organizations\Data\ChangeProjectRoleData;
use App\Modules\Organizations\Data\RemoveProjectMemberData;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Events\MemberRemovedFromProject;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->world = portalWorld();
    $this->maya = $this->world['maya'];
    $this->storefront = $this->world['storefront'];
    $this->page = '/acme/storefront/production/settings/members';
});

function actor_for(User $user): Actor
{
    return Actor::user($user->id, $user->name, Channel::Dashboard);
}

function membership_of(string $userId, string $projectId): ProjectMembership
{
    return Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $userId)->where('project_id', $projectId)->firstOrFail());
}

function change_project_role(Actor $by, string $organizationId, string $projectId, ProjectMembership $membership, ProjectRole $role, ?int $version = null): void
{
    app(ChangeProjectRole::class)->handle($by, new ChangeProjectRoleData($organizationId, $projectId, $membership->id, $role, $version ?? $membership->version));
}

describe('changing a role', function () {
    it('changes it, bumps the version and records before and after', function () {
        $jon = membership_of($this->world['jon']->id, $this->storefront->id);

        change_project_role(actor_for($this->maya), $this->world['acme']->id, $this->storefront->id, $jon, ProjectRole::Developer);

        $after = membership_of($this->world['jon']->id, $this->storefront->id);
        $event = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'project_member.role_changed')->firstOrFail());

        expect($after->role)->toBe(ProjectRole::Developer)
            ->and($after->version)->toBe(2)
            ->and($event->details)->toMatchArray(['before' => 'publisher', 'after' => 'developer'])
            ->and(OutboxMessageRecord::query()->where('type', 'internal.member.role_changed')->count())->toBe(1);
    });

    it('does nothing when the role is the same', function () {
        $jon = membership_of($this->world['jon']->id, $this->storefront->id);

        change_project_role(actor_for($this->maya), $this->world['acme']->id, $this->storefront->id, $jon, ProjectRole::Publisher);

        expect(membership_of($this->world['jon']->id, $this->storefront->id)->version)->toBe(1);
    });

    it('refuses a change made from an out-of-date list', function () {
        $jon = membership_of($this->world['jon']->id, $this->storefront->id);
        change_project_role(actor_for($this->maya), $this->world['acme']->id, $this->storefront->id, $jon, ProjectRole::Developer);

        expect(fn () => change_project_role(actor_for($this->maya), $this->world['acme']->id, $this->storefront->id, $jon, ProjectRole::Viewer, 1))
            ->toThrow(VersionConflict::class);
        expect(membership_of($this->world['jon']->id, $this->storefront->id)->role)->toBe(ProjectRole::Developer);
    });

    it('refuses to change your own role', function () {
        $own = membership_of($this->maya->id, $this->storefront->id);

        expect(fn () => change_project_role(actor_for($this->maya), $this->world['acme']->id, $this->storefront->id, $own, ProjectRole::Viewer))
            ->toThrow(ValidationException::class, 'own role');
    });

    it('keeps one Owner when two Owners demote each other at the same moment', function () {
        $second = $this->world['jon'];
        Tenancy::crossTenant('make an owner', fn () => membership_of($second->id, $this->storefront->id)->forceFill(['role' => ProjectRole::Owner])->save());
        $secondActor = actor_for($second);
        $scope = new ProjectScope($this->world['acme']->id, $this->storefront->id);
        expect($secondActor->can(Capability::MembersManage, $scope))->toBeTrue();

        change_project_role(actor_for($this->maya), $this->world['acme']->id, $this->storefront->id, membership_of($second->id, $this->storefront->id), ProjectRole::Viewer);

        expect(fn () => change_project_role($secondActor, $this->world['acme']->id, $this->storefront->id, membership_of($this->maya->id, $this->storefront->id), ProjectRole::Viewer))
            ->toThrow(LastOwner::class);
        expect(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('project_id', $this->storefront->id)->where('role', 'owner')->count()))->toBe(1);
    });

    it('is for project Owners: Publishers, Developers, Viewers and Org Owners outside the project cannot', function (string $who) {
        $actor = actor_for($this->world[$who]);
        $target = membership_of($this->world['ana']->id, $this->storefront->id);

        expect(fn () => change_project_role($actor, $this->world['acme']->id, $this->storefront->id, $target, ProjectRole::Developer))->toThrow(AuthorizationDenied::class);
    })->with(['jon', 'ana', 'leo']);

    it('does not reach a membership of another project', function () {
        $other = membership_of($this->maya->id, $this->world['checkout']->id);

        expect(fn () => change_project_role(actor_for($this->maya), $this->world['acme']->id, $this->storefront->id, $other, ProjectRole::Viewer))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('removing and leaving', function () {
    function remove_from_project(Actor $by, array $world, ProjectMembership $membership): void
    {
        app(RemoveProjectMember::class)->handle($by, new RemoveProjectMemberData($world['acme']->id, $world['storefront']->id, $membership->id));
    }

    it('removes another person, audits it and announces it', function () {
        Event::fake([MemberRemovedFromProject::class]);

        remove_from_project(actor_for($this->maya), $this->world, membership_of($this->world['ana']->id, $this->storefront->id));

        expect(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->world['ana']->id)->where('project_id', $this->storefront->id)->count()))->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'project_member.removed')->firstOrFail()->details['left']))->toBeFalse();
        Event::assertDispatched(MemberRemovedFromProject::class, fn ($e) => $e->userId === $this->world['ana']->id && $e->projectId === $this->storefront->id);
    });

    it('keeps their place in other projects', function () {
        remove_from_project(actor_for($this->maya), $this->world, membership_of($this->world['jon']->id, $this->storefront->id));

        expect(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->world['jon']->id)->count()))->toBe(1);
    });

    it('lets anyone in the project leave it, whatever their role', function () {
        remove_from_project(actor_for($this->world['ana']), $this->world, membership_of($this->world['ana']->id, $this->storefront->id));

        expect(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'project_member.removed')->firstOrFail()->details['left']))->toBeTrue();
    });

    it('does not let the last Owner leave or be removed', function () {
        Tenancy::crossTenant('only one owner', fn () => ProjectMembership::query()->where('project_id', $this->storefront->id)->where('user_id', '!=', $this->maya->id)->delete());

        expect(fn () => remove_from_project(actor_for($this->maya), $this->world, membership_of($this->maya->id, $this->storefront->id)))->toThrow(LastOwner::class);
    });

    it('does not let someone without member management remove another', function () {
        expect(fn () => remove_from_project(actor_for($this->world['jon']), $this->world, membership_of($this->world['ana']->id, $this->storefront->id)))->toThrow(AuthorizationDenied::class);
        expect(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->world['ana']->id)->count()))->toBe(1);
    });

    it('does not announce a removal that failed', function () {
        Event::fake([MemberRemovedFromProject::class]);

        try {
            remove_from_project(actor_for($this->world['jon']), $this->world, membership_of($this->world['ana']->id, $this->storefront->id));
        } catch (AuthorizationDenied) {
        }

        Event::assertNotDispatched(MemberRemovedFromProject::class);
    });
});

describe('the Members tab', function () {
    it('shows an Owner the people, the role counts and the controls', function () {
        $this->actingAs($this->maya)->get($this->page)->assertInertia(fn (Assert $page) => $page
            ->component('project/settings/Members')->where('canManage', true)->has('members', 3)
            ->where('roleCounts', ['owner' => 1, 'publisher' => 1, 'developer' => 0, 'viewer' => 1])
            ->where('project.name', 'Storefront')->where('currentUserId', $this->maya->id));
    });

    it('shows a Viewer the same people read-only, with no invitations', function () {
        $this->actingAs($this->world['ana'])->get($this->page)->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)->has('members', 3)->has('invitations', 0));
    });

    it('shows a Developer read-only', function () {
        $this->actingAs($this->world['jon'])->get('/acme/checkout-api/production/settings/members')->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)->where('members', fn ($members) => count($members) === 2));
    });

    it('answers 404 to an Org Owner who is not in the project, and to someone from another organization', function () {
        Tenancy::crossTenant('leave', fn () => ProjectMembership::query()->where('user_id', $this->maya->id)->where('project_id', $this->storefront->id)->delete());

        $this->actingAs($this->maya)->get($this->page)->assertNotFound();
        $this->actingAs($this->world['gina'])->get($this->page)->assertNotFound();
        $this->actingAs($this->world['leo'])->get($this->page)->assertNotFound();
    });

    it('lists pending invitations that include this project, and none for other projects', function () {
        invite_people(['actor' => actor_for($this->maya), 'organization' => $this->world['acme'], 'project' => $this->storefront], ['here@x.dev']);
        invite_people(['actor' => actor_for($this->maya), 'organization' => $this->world['acme'], 'project' => $this->world['checkout']], ['elsewhere@x.dev']);

        $this->actingAs($this->maya)->get($this->page)->assertInertia(fn (Assert $page) => $page
            ->has('invitations', 1)->where('invitations.0.email', 'here@x.dev')->where('invitations.0.role', 'developer')->where('invitations.0.status', 'pending'));
    });

    it('is reached from the Settings entry in the sidebar', function () {
        $this->actingAs($this->maya)->get('/acme/storefront/production/overview')->assertInertia(fn (Assert $page) => $page
            ->where('navigation', fn ($navigation) => collect($navigation)->contains(fn ($item) => $item['key'] === 'settings' && $item['href'] === '/acme/storefront/production/settings/general' && $item['group'] === 'Administration')));
    });
});

describe('from the Members tab', function () {
    it('invites to this project as an organization Member, with the chosen role', function () {
        $this->actingAs($this->maya)->post("{$this->page}/invitations", ['emails' => 'a@x.dev, b@x.dev', 'projects' => [['id' => 'ignored', 'role' => 'publisher']]])
            ->assertRedirect()->assertSessionHas('toast.message', '2 invitations sent.');

        $invitations = Tenancy::crossTenant('inspect', fn () => Invitation::query()->with('grants')->get());

        expect($invitations)->toHaveCount(2)
            ->and($invitations->first()->org_role->value)->toBe('member')
            ->and($invitations->first()->grants)->toHaveCount(1)
            ->and($invitations->first()->grants->first()->project_id)->toBe($this->storefront->id)
            ->and($invitations->first()->grants->first()->role)->toBe(ProjectRole::Publisher);
    });

    it('refuses someone who cannot manage members', function () {
        $this->actingAs($this->world['jon'])->post("{$this->page}/invitations", ['emails' => 'a@x.dev', 'projects' => [['id' => 'x', 'role' => 'viewer']]])->assertForbidden();
        $this->actingAs($this->world['ana'])->post("{$this->page}/invitations", ['emails' => 'a@x.dev', 'projects' => [['id' => 'x', 'role' => 'viewer']]])->assertForbidden();
    });

    it('needs a role, and reports addresses that cannot be invited', function () {
        $this->actingAs($this->maya)->from($this->page)->post("{$this->page}/invitations", ['emails' => 'a@x.dev'])->assertSessionHasErrors('projects');
        $this->actingAs($this->maya)->from($this->page)->post("{$this->page}/invitations", ['emails' => 'jon@acme.dev', 'projects' => [['id' => 'x', 'role' => 'viewer']]])->assertSessionHasErrors('emails');
    });

    it('sends again and withdraws an invitation to the project', function () {
        $id = invite_people(['actor' => actor_for($this->maya), 'organization' => $this->world['acme'], 'project' => $this->storefront], ['here@x.dev'])->created[0]['invitationId'];

        $this->actingAs($this->maya)->post("{$this->page}/invitations/{$id}/resend")->assertRedirect()->assertSessionHas('toast.message', 'Invitation sent again.');
        $this->actingAs($this->maya)->delete("{$this->page}/invitations/{$id}")->assertRedirect()->assertSessionHas('toast.message', 'Invitation withdrawn.');

        expect(invitation_status($id))->toBe('revoked');
    });

    it('refuses a Publisher sending or withdrawing', function () {
        $id = invite_people(['actor' => actor_for($this->maya), 'organization' => $this->world['acme'], 'project' => $this->storefront], ['here@x.dev'])->created[0]['invitationId'];

        $this->actingAs($this->world['jon'])->post("{$this->page}/invitations/{$id}/resend")->assertForbidden();
        $this->actingAs($this->world['jon'])->delete("{$this->page}/invitations/{$id}")->assertForbidden();
    });

    it('changes a role from the list and shows a stale list as a conflict', function () {
        $jon = membership_of($this->world['jon']->id, $this->storefront->id);

        $this->actingAs($this->maya)->patch("{$this->page}/{$jon->id}", ['role' => 'developer', 'expected_version' => 1])->assertRedirect()->assertSessionHas('toast.message', 'Role changed.');
        $this->actingAs($this->maya)->from($this->page)->patch("{$this->page}/{$jon->id}", ['role' => 'viewer', 'expected_version' => 1])->assertSessionHasErrors('member');

        expect(membership_of($this->world['jon']->id, $this->storefront->id)->role)->toBe(ProjectRole::Developer);
    });

    it('removes a member from the list', function () {
        $ana = membership_of($this->world['ana']->id, $this->storefront->id);

        $this->actingAs($this->maya)->delete("{$this->page}/{$ana->id}")->assertRedirect()->assertSessionHas('toast.message', 'Member removed.');

        expect(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->world['ana']->id)->where('project_id', $this->storefront->id)->count()))->toBe(0);
    });

    it('lets a member leave the project and goes home', function () {
        $this->actingAs($this->world['ana'])->delete('/acme/storefront/leave')->assertRedirect('/')->assertSessionHas('toast.message', 'You left the project.');

        expect(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->world['ana']->id)->count()))->toBe(0);
    });

    it('says why the last Owner cannot leave', function () {
        Tenancy::crossTenant('only one owner', fn () => ProjectMembership::query()->where('project_id', $this->storefront->id)->where('user_id', '!=', $this->maya->id)->delete());

        $this->actingAs($this->maya)->from($this->page)->delete('/acme/storefront/leave')->assertSessionHasErrors('member');
    });

    it('does not reach another organization\'s memberships', function () {
        $theirs = Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->world['gina']->id)->firstOrFail());

        $this->actingAs($this->maya)->patch("{$this->page}/{$theirs->id}", ['role' => 'viewer', 'expected_version' => 1])->assertNotFound();
        $this->actingAs($this->maya)->delete("{$this->page}/{$theirs->id}")->assertNotFound();
    });
});
