<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\AcceptInvitation;
use App\Modules\Organizations\Actions\IssueInvitationLink;
use App\Modules\Organizations\Actions\RevokeInvitation;
use App\Modules\Organizations\Data\AcceptInvitationData;
use App\Modules\Organizations\Data\ChangeInvitationData;
use App\Modules\Organizations\Data\InviteGrant;
use App\Modules\Organizations\Domain\Exceptions\DomainNotAllowed;
use App\Modules\Organizations\Domain\Exceptions\InvitationUnavailable;
use App\Modules\Organizations\Domain\Exceptions\InvitationWrongAccount;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    $this->owner = person(OrgRole::Owner, ProjectRole::Owner);
});

function org_role_of(string $userId, string $organizationId): ?OrgRole
{
    return Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('organization_id', $organizationId)->where('user_id', $userId)->first()?->role);
}

function project_role_of(string $userId, string $projectId): ?ProjectRole
{
    return Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('project_id', $projectId)->where('user_id', $userId)->first()?->role);
}

function accept_as_new(string $token, string $name = 'Dev Person', string $password = 'correct horse battery')
{
    return app(AcceptInvitation::class)->handle(new AcceptInvitationData($token, null, $name, $password));
}

describe('the invitation page', function () {
    it('invites someone with no account to create one, showing their address read-only', function () {
        $link = invitation_link($this->owner);

        $this->get("/invitations/{$link['token']}")->assertInertia(fn (Assert $page) => $page
            ->component('auth/AcceptInvitation')
            ->where('mode', 'create')
            ->where('email', 'dev@northwind.studio')
            ->where('organizationName', $this->owner['organization']->name)
            ->where('inviterName', $this->owner['user']->name)
            ->where('orgRole', 'member')
            ->where('projects.0.name', $this->owner['project']->name)
            ->where('projects.0.role', 'developer')
            ->has('expiresAt'));
    });

    it('asks the right person, signed in, to confirm', function () {
        $link = invitation_link($this->owner);
        $dev = verified_user('dev@northwind.studio');

        $this->actingAs($dev)->get("/invitations/{$link['token']}")->assertInertia(fn (Assert $page) => $page
            ->component('auth/AcceptInvitation')->where('mode', 'confirm'));
    });

    it('tells a different signed-in person, masking the invited address', function () {
        $link = invitation_link($this->owner);
        $maya = verified_user('maya@acme.dev');

        $this->actingAs($maya)->get("/invitations/{$link['token']}")->assertInertia(fn (Assert $page) => $page
            ->component('auth/InvitationWrongAccount')
            ->where('invitedEmail', 'd•••@northwind.studio')
            ->where('signedInAs', 'maya@acme.dev'));
    });

    it('does not reveal the invited address to a different signed-in person', function () {
        $link = invitation_link($this->owner);

        $body = $this->actingAs(verified_user('maya@acme.dev'))->get("/invitations/{$link['token']}")->getContent();

        expect($body)->not->toContain('dev@northwind.studio');
    });

    it('says an expired invitation expired, naming the organization and who sent it', function () {
        $link = invitation_link($this->owner);
        $this->travel(8)->days();

        $this->get("/invitations/{$link['token']}")->assertInertia(fn (Assert $page) => $page
            ->component('auth/InvitationUnavailable')->where('reason', 'expired')
            ->where('organizationName', $this->owner['organization']->name)->where('inviterName', $this->owner['user']->name));
    });

    it('says a withdrawn invitation was withdrawn, and a used one was used', function () {
        $revoked = invitation_link($this->owner, 'a@northwind.studio');
        app(RevokeInvitation::class)->handle($this->owner['actor'], new ChangeInvitationData($this->owner['organization']->id, $revoked['invitation']->id));
        $used = invitation_link($this->owner, 'b@northwind.studio');
        accept_as_new($used['token']);

        $this->get("/invitations/{$revoked['token']}")->assertInertia(fn (Assert $page) => $page->where('reason', 'revoked'));
        $this->get("/invitations/{$used['token']}")->assertInertia(fn (Assert $page) => $page->where('reason', 'used')->where('organizationName', null)->where('inviterName', null));
    });

    it('gives an unknown link the same plain answer, with no names', function (string $token) {
        invitation_link($this->owner);

        $this->get("/invitations/{$token}")->assertInertia(fn (Assert $page) => $page
            ->component('auth/InvitationUnavailable')->where('reason', 'unknown')->where('organizationName', null)->where('inviterName', null));
    })->with([str_repeat('a', 43), 'ab_cd-'.'x'.str_repeat('0', 36)]);

    it('does not route a link of the wrong shape', function () {
        $this->get('/invitations/short')->assertNotFound();
        $this->get('/invitations/'.str_repeat('a', 44))->assertNotFound();
    });

    it('does not show an old link after a new one was sent', function () {
        $link = invitation_link($this->owner);
        $new = IssueInvitationLink::class;
        app($new)->handle($this->owner['organization']->id, $link['invitation']->id);

        $this->get("/invitations/{$link['token']}")->assertInertia(fn (Assert $page) => $page->where('reason', 'unknown'));
    });
});

describe('accepting with a new account', function () {
    it('creates a confirmed account, joins the organization and the projects, and signs the person in', function () {
        $link = invitation_link($this->owner);

        $this->post("/invitations/{$link['token']}/accept", ['name' => 'Dev Person', 'password' => 'correct horse battery'])
            ->assertRedirect(route('organization.home', ['organization' => $this->owner['organization']->slug]));

        $user = User::query()->where('email', 'dev@northwind.studio')->firstOrFail();

        expect($user->name)->toBe('Dev Person')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('correct horse battery', $user->password))->toBeTrue()
            ->and(org_role_of($user->id, $this->owner['organization']->id))->toBe(OrgRole::Member)
            ->and(project_role_of($user->id, $this->owner['project']->id))->toBe(ProjectRole::Developer)
            ->and(invitation_status($link['invitation']->id))->toBe('accepted');
        $this->assertAuthenticatedAs($user, 'web');
    });

    it('uses the address on the invitation, whatever the form says', function () {
        $link = invitation_link($this->owner);

        $this->post("/invitations/{$link['token']}/accept", ['name' => 'Dev', 'password' => 'correct horse battery', 'email' => 'someone@else.org']);

        expect(User::query()->where('email', 'someone@else.org')->exists())->toBeFalse()
            ->and(User::query()->where('email', 'dev@northwind.studio')->exists())->toBeTrue();
    });

    it('applies the password rules and creates nothing when they fail', function (string $password) {
        $link = invitation_link($this->owner);

        $this->from("/invitations/{$link['token']}")->post("/invitations/{$link['token']}/accept", ['name' => 'Dev', 'password' => $password])->assertSessionHasErrors('password');

        expect(User::query()->where('email', 'dev@northwind.studio')->exists())->toBeFalse()
            ->and(invitation_status($link['invitation']->id))->toBe('pending');
    })->with(['short one', 'Password1234', 'dev@northwind.studio']);

    it('needs a name', function () {
        $link = invitation_link($this->owner);

        $this->from("/invitations/{$link['token']}")->post("/invitations/{$link['token']}/accept", ['name' => '  ', 'password' => 'correct horse battery'])->assertSessionHasErrors('name');
    });

    it('says the address already has an account, and uses nothing up', function () {
        $link = invitation_link($this->owner);
        verified_user('dev@northwind.studio');

        $this->from("/invitations/{$link['token']}")->post("/invitations/{$link['token']}/accept", ['name' => 'Dev', 'password' => 'correct horse battery'])
            ->assertSessionHasErrors(['email' => 'You already have an account. Sign in to accept.']);

        expect(invitation_status($link['invitation']->id))->toBe('pending');
    });
});

describe('accepting while signed in', function () {
    it('joins the right person and changes nothing about their account', function () {
        $link = invitation_link($this->owner);
        $dev = verified_user('dev@northwind.studio');

        $this->actingAs($dev)->post("/invitations/{$link['token']}/accept")->assertRedirect();

        expect(org_role_of($dev->id, $this->owner['organization']->id))->toBe(OrgRole::Member)
            ->and(project_role_of($dev->id, $this->owner['project']->id))->toBe(ProjectRole::Developer)
            ->and(Hash::check('correct horse battery', $dev->fresh()->password))->toBeTrue()
            ->and(User::query()->count())->toBe(2);
    });

    it('refuses a different signed-in person and uses nothing up', function () {
        $link = invitation_link($this->owner);
        $maya = verified_user('maya@acme.dev');

        expect(fn () => app(AcceptInvitation::class)->handle(new AcceptInvitationData($link['token'], $maya->id)))->toThrow(InvitationWrongAccount::class);
        $this->actingAs($maya)->post("/invitations/{$link['token']}/accept")->assertRedirect(route('invitations.show', ['token' => $link['token']]));

        expect(invitation_status($link['invitation']->id))->toBe('pending')
            ->and(org_role_of($maya->id, $this->owner['organization']->id))->toBeNull()
            ->and(project_role_of($maya->id, $this->owner['project']->id))->toBeNull();
    });

    it('can still be used by the right person after a wrong one tried', function () {
        $link = invitation_link($this->owner);
        $maya = verified_user('maya@acme.dev');
        $this->actingAs($maya)->post("/invitations/{$link['token']}/accept");

        $dev = verified_user('dev@northwind.studio');
        $this->actingAs($dev)->post("/invitations/{$link['token']}/accept");

        expect(invitation_status($link['invitation']->id))->toBe('accepted');
    });

    it('matches the address in any letter case', function () {
        $link = invitation_link($this->owner);
        $dev = verified_user('Dev@Northwind.Studio');

        $this->actingAs($dev)->post("/invitations/{$link['token']}/accept")->assertRedirect();

        expect(invitation_status($link['invitation']->id))->toBe('accepted');
    });

    it('lets a wrong-account visitor sign out and come back to the same invitation', function () {
        $link = invitation_link($this->owner);

        $this->actingAs(verified_user('maya@acme.dev'))->post("/invitations/{$link['token']}/switch")->assertRedirect(route('invitations.show', ['token' => $link['token']]));

        $this->assertGuest('web');
    });
});

describe('what joining does', function () {
    it('never lowers an Org Owner invited as a Member', function () {
        $dev = verified_user('dev@northwind.studio');
        OrganizationMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'user_id' => $dev->id, 'role' => OrgRole::Owner]);
        $link = invitation_link($this->owner, 'dev@northwind.studio', OrgRole::Member);

        app(AcceptInvitation::class)->handle(new AcceptInvitationData($link['token'], $dev->id));

        expect(org_role_of($dev->id, $this->owner['organization']->id))->toBe(OrgRole::Owner);
    });

    it('raises a Member invited as an Org Owner', function () {
        $dev = verified_user('dev@northwind.studio');
        OrganizationMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'user_id' => $dev->id, 'role' => OrgRole::Member]);
        $link = invitation_link($this->owner, 'dev@northwind.studio', OrgRole::Owner, []);

        app(AcceptInvitation::class)->handle(new AcceptInvitationData($link['token'], $dev->id));

        expect(org_role_of($dev->id, $this->owner['organization']->id))->toBe(OrgRole::Owner);
    });

    it('keeps the role of a project the person already belongs to, and says so', function () {
        $dev = verified_user('dev@northwind.studio');
        OrganizationMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'user_id' => $dev->id, 'role' => OrgRole::Member]);
        ProjectMembership::factory()->create(['organization_id' => $this->owner['organization']->id, 'project_id' => $this->owner['project']->id, 'user_id' => $dev->id, 'role' => ProjectRole::Owner]);
        $second = Project::factory()->create(['organization_id' => $this->owner['organization']->id]);
        $link = invitation_link($this->owner, 'dev@northwind.studio', OrgRole::Member, [
            new InviteGrant($this->owner['project']->id, ProjectRole::Viewer),
            new InviteGrant($second->id, ProjectRole::Publisher),
        ]);

        $result = app(AcceptInvitation::class)->handle(new AcceptInvitationData($link['token'], $dev->id));

        expect(project_role_of($dev->id, $this->owner['project']->id))->toBe(ProjectRole::Owner)
            ->and(project_role_of($dev->id, $second->id))->toBe(ProjectRole::Publisher)
            ->and($result->existingProjectIds)->toBe([$this->owner['project']->id])
            ->and($result->addedProjectIds)->toBe([$second->id])
            ->and($result->createdAccount)->toBeFalse();
    });

    it('records who added the person to each project', function () {
        $link = invitation_link($this->owner);
        $result = accept_as_new($link['token']);

        $membership = Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $result->userId)->firstOrFail());

        expect($membership->added_by)->toBe($this->owner['user']->id);
    });

    it('records the acceptance in the audit trail and the outbox', function () {
        $link = invitation_link($this->owner);
        $result = accept_as_new($link['token']);

        $actions = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->orderBy('id')->pluck('action')->all());

        expect($actions)->toContain('invitation.created', 'invitation.accepted', 'project_member.added')
            ->and(OutboxMessageRecord::query()->where('type', 'internal.member.added')->firstOrFail()->payload)->toBe(['user_id' => $result->userId]);
    });

    it('lets one person hold several organizations', function () {
        $dev = verified_user('dev@northwind.studio');
        $mine = person(OrgRole::Owner, ProjectRole::Owner);
        OrganizationMembership::factory()->create(['organization_id' => $mine['organization']->id, 'user_id' => $dev->id, 'role' => OrgRole::Owner]);
        $link = invitation_link($this->owner);

        app(AcceptInvitation::class)->handle(new AcceptInvitationData($link['token'], $dev->id));

        expect(org_role_of($dev->id, $mine['organization']->id))->toBe(OrgRole::Owner)
            ->and(org_role_of($dev->id, $this->owner['organization']->id))->toBe(OrgRole::Member);
    });
});

describe('when it cannot be used', function () {
    it('works once', function () {
        $link = invitation_link($this->owner);
        accept_as_new($link['token']);

        expect(fn () => accept_as_new($link['token'], 'Other', 'another good password'))->toThrow(InvitationUnavailable::class);
        expect(User::query()->where('email', 'dev@northwind.studio')->count())->toBe(1);
    });

    it('creates one membership when two requests arrive in turn', function () {
        $link = invitation_link($this->owner);
        $dev = verified_user('dev@northwind.studio');

        app(AcceptInvitation::class)->handle(new AcceptInvitationData($link['token'], $dev->id));
        expect(fn () => app(AcceptInvitation::class)->handle(new AcceptInvitationData($link['token'], $dev->id)))->toThrow(InvitationUnavailable::class);

        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $dev->id)->where('organization_id', $this->owner['organization']->id)->count()))->toBe(1)
            ->and(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $dev->id)->count()))->toBe(1);
    });

    it('stops working after seven days, checked at the moment of accepting', function () {
        $link = invitation_link($this->owner);
        $this->travel(7)->days();
        $this->travel(1)->minutes();

        expect(fn () => accept_as_new($link['token']))->toThrow(InvitationUnavailable::class);
        expect(invitation_status($link['invitation']->id))->toBe('pending');
    });

    it('still works on the last day, before the job has marked it', function () {
        $link = invitation_link($this->owner);
        $this->travel(6)->days();

        expect(accept_as_new($link['token'])->createdAccount)->toBeTrue();
    });

    it('stops working once withdrawn', function () {
        $link = invitation_link($this->owner);
        app(RevokeInvitation::class)->handle($this->owner['actor'], new ChangeInvitationData($this->owner['organization']->id, $link['invitation']->id));

        expect(fn () => accept_as_new($link['token']))->toThrow(InvitationUnavailable::class);
    });

    it('does not take a queued invitation, whose email has not been sent', function () {
        $this->owner['user']->forceFill(['email_verified_at' => null])->save();
        $result = invite_people($this->owner, ['dev@northwind.studio']);
        $token = str_repeat('q', 43);

        expect(invitation_status($result->created[0]['invitationId']))->toBe('queued')
            ->and(fn () => accept_as_new($token))->toThrow(InvitationUnavailable::class);
    });

    it('rechecks the allowed domains when it is accepted', function () {
        $link = invitation_link($this->owner);
        Organization::query()->whereKey($this->owner['organization']->id)->update(['allowed_email_domains' => json_encode(['acme.dev'])]);

        expect(fn () => accept_as_new($link['token']))->toThrow(DomainNotAllowed::class);
        expect(invitation_status($link['invitation']->id))->toBe('pending')->and(User::query()->where('email', 'dev@northwind.studio')->exists())->toBeFalse();
    });

    it('answers the page the same for every reason the link cannot be used', function () {
        $link = invitation_link($this->owner);
        Organization::query()->whereKey($this->owner['organization']->id)->update(['allowed_email_domains' => json_encode(['acme.dev'])]);

        $this->post("/invitations/{$link['token']}/accept", ['name' => 'Dev', 'password' => 'correct horse battery'])
            ->assertRedirect(route('invitations.show', ['token' => $link['token']]));
    });

    it('limits how fast a link can be tried', function () {
        RateLimiter::clear('127.0.0.1');
        $statuses = [];

        foreach (range(1, 11) as $n) {
            $statuses[] = $this->post('/invitations/'.str_repeat('z', 43).'/accept', ['name' => 'x', 'password' => 'y'])->getStatusCode();
        }

        expect(end($statuses))->toBe(429);
    });

    it('leaves nothing behind when a later write fails', function () {
        $link = invitation_link($this->owner);
        app()->bind(Outbox::class, fn () => throw new RuntimeException('outbox down'));

        expect(fn () => accept_as_new($link['token']))->toThrow(RuntimeException::class);
        expect(User::query()->where('email', 'dev@northwind.studio')->exists())->toBeFalse()
            ->and(invitation_status($link['invitation']->id))->toBe('pending');
    });
});
