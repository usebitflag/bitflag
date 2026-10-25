<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->world = portalWorld();
    $this->maya = $this->world['maya'];
    $this->jon = $this->world['jon'];
});

function acme(): Organization
{
    return Organization::query()->where('slug', 'acme')->firstOrFail();
}

describe('General', function () {
    it('shows the settings to a Member, read-only, and to an Owner, editable', function () {
        $this->actingAs($this->jon)->get('/acme/settings/general')->assertInertia(fn (Assert $page) => $page
            ->component('organization/General')->where('canManage', false)->where('settings.name', 'Acme')->where('settings.slug', 'acme')->where('settings.version', 1));

        $this->actingAs($this->maya)->get('/acme/settings/general')->assertInertia(fn (Assert $page) => $page->where('canManage', true));
    });

    it('sends the settings link to General', function () {
        $this->actingAs($this->jon)->get('/acme/settings')->assertRedirect('/acme/settings/general');
    });

    it('lets an Owner rename the organization and change its URL name, going to the new address', function () {
        $this->actingAs($this->maya)->put('/acme/settings/general', ['name' => 'Acme Inc', 'slug' => 'acme-inc', 'version' => 1])
            ->assertRedirect('/acme-inc/settings/general');

        expect(acme_by('acme-inc')->name)->toBe('Acme Inc')->and(acme_by('acme-inc')->version)->toBe(2);
        $this->actingAs($this->maya)->get('/acme/settings/general')->assertNotFound();
    });

    it('refuses a Member', function () {
        $this->actingAs($this->jon)->put('/acme/settings/general', ['name' => 'X', 'slug' => 'xyz', 'version' => 1])->assertForbidden();

        expect(acme()->name)->toBe('Acme');
    });

    it('asks to reload when someone else saved first', function () {
        $this->actingAs($this->maya)->put('/acme/settings/general', ['name' => 'First', 'slug' => 'acme', 'version' => 1]);

        $this->actingAs($this->maya)->from('/acme/settings/general')->put('/acme/settings/general', ['name' => 'Second', 'slug' => 'acme', 'version' => 1])
            ->assertSessionHasErrors('version');

        expect(acme()->name)->toBe('First');
    });

    it('refuses a name that cannot be used', function (array $change, string $field) {
        $this->actingAs($this->maya)->from('/acme/settings/general')->put('/acme/settings/general', ['name' => 'Acme', 'slug' => 'acme', 'version' => 1, ...$change])->assertSessionHasErrors($field);
    })->with([
        'reserved' => [['slug' => 'signup'], 'slug'],
        'taken' => [['slug' => 'globex'], 'slug'],
        'no name' => [['name' => ''], 'name'],
    ]);

    it('answers 404 to someone outside the organization', function () {
        $this->actingAs($this->world['gina'])->get('/acme/settings/general')->assertNotFound();
        $this->actingAs($this->world['gina'])->put('/acme/settings/general', ['name' => 'X', 'slug' => 'xyz', 'version' => 1])->assertNotFound();
    });

    it('is for signed-in people', function () {
        $this->get('/acme/settings/general')->assertRedirect('/signin');
    });
});

function acme_by(string $slug): Organization
{
    return Organization::query()->where('slug', $slug)->firstOrFail();
}

describe('Security', function () {
    it('is for Org Owners', function () {
        $this->actingAs($this->jon)->get('/acme/settings/security')->assertForbidden();
        $this->actingAs($this->jon)->put('/acme/settings/security', ['require_mfa' => true, 'allowed_email_domains' => [], 'version' => 1])->assertForbidden();

        $this->actingAs($this->maya)->get('/acme/settings/security')->assertOk();
    });

    it('shows the settings and how many members have not set up two-factor', function () {
        enroll_two_factor($this->jon);

        $this->actingAs($this->maya)->get('/acme/settings/security')->assertInertia(fn (Assert $page) => $page
            ->component('organization/Security')->where('settings.requireMfa', false)->where('settings.idleTimeoutMinutes', null)
            ->where('settings.allowedEmailDomains', [])->where('membersWithoutTwoFactor', 3));
    });

    it('saves the settings', function () {
        $this->actingAs($this->maya)->put('/acme/settings/security', [
            'require_mfa' => true, 'idle_timeout_minutes' => 720, 'allowed_email_domains' => ['Acme.dev'], 'version' => 1,
        ])->assertRedirect();

        $organization = acme();

        expect($organization->require_mfa)->toBeTrue()
            ->and($organization->session_idle_timeout_minutes)->toBe(720)
            ->and($organization->allowed_email_domains)->toBe(['acme.dev']);
    });

    it('takes no idle limit as the platform default', function () {
        $this->actingAs($this->maya)->put('/acme/settings/security', ['require_mfa' => false, 'idle_timeout_minutes' => 60, 'allowed_email_domains' => ['acme.dev'], 'version' => 1]);
        $this->actingAs($this->maya)->put('/acme/settings/security', ['require_mfa' => false, 'idle_timeout_minutes' => '', 'allowed_email_domains' => ['acme.dev'], 'version' => 2])->assertSessionHasNoErrors();

        expect(acme()->session_idle_timeout_minutes)->toBeNull();
    });

    it('refuses what cannot be used', function (array $change, string $field) {
        $this->actingAs($this->maya)->from('/acme/settings/security')->put('/acme/settings/security', ['require_mfa' => false, 'allowed_email_domains' => ['acme.dev'], 'version' => 1, ...$change])->assertSessionHasErrors($field);
    })->with([
        'idle option' => [['idle_timeout_minutes' => 30], 'idle_timeout_minutes'],
        'not a domain' => [['allowed_email_domains' => ['not a domain']], 'allowed_email_domains'],
    ]);

    it('asks to reload when someone else saved first', function () {
        // Turning two-factor on applies to the owner too, so they have it set up.
        enroll_two_factor($this->maya);
        $this->actingAs($this->maya)->put('/acme/settings/security', ['require_mfa' => true, 'allowed_email_domains' => ['acme.dev'], 'version' => 1]);

        $this->actingAs($this->maya)->from('/acme/settings/security')->put('/acme/settings/security', ['require_mfa' => false, 'allowed_email_domains' => ['acme.dev'], 'version' => 1])->assertSessionHasErrors('version');
    });
});

describe('Members', function () {
    it('shows an Owner every member with role, projects, two-factor and last activity', function () {
        enroll_two_factor($this->jon);
        $this->jon->forceFill(['last_active_at' => now()->subDay()])->save();

        $this->actingAs($this->maya)->get('/acme/settings/members')->assertInertia(fn (Assert $page) => $page
            ->component('organization/Members')->where('canManage', true)->has('members', 4)
            ->where('members', function ($members) {
                $jon = collect($members)->firstWhere('email', 'jon@acme.dev');

                return $jon['orgRole'] === 'member' && $jon['twoFactor'] === true && $jon['lastActiveAt'] !== null
                    && collect($jon['projects'])->pluck('role', 'name')->all() === ['Checkout API' => 'developer', 'Storefront' => 'publisher'];
            })
            ->has('projects', 2));
    });

    it('shows a Member only their own row and no invitations', function () {
        $this->actingAs($this->jon)->get('/acme/settings/members')->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)->has('members', 1)->where('members.0.email', 'jon@acme.dev')->has('invitations', 0)->has('projects', 0));
    });

    it('never lists people from other organizations', function () {
        $body = $this->actingAs($this->maya)->get('/acme/settings/members')->getContent();

        expect($body)->not->toContain('gina@globex.dev');
    });

    it('invites people with a role and projects', function () {
        $storefront = PublicId::encode(IdPrefix::Project, $this->world['storefront']->id);

        $this->actingAs($this->maya)->post('/acme/settings/members/invitations', [
            'emails' => 'a@acme.dev, b@acme.dev', 'org_role' => 'member', 'projects' => [['id' => $storefront, 'role' => 'viewer']],
        ])->assertRedirect()->assertSessionHas('toast.message', '2 invitations sent.');

        $invitations = Tenancy::crossTenant('inspect', fn () => Invitation::query()->with('grants')->get());

        expect($invitations)->toHaveCount(2)->and($invitations->first()->grants->first()->project_id)->toBe($this->world['storefront']->id);
        $this->actingAs($this->maya)->get('/acme/settings/members')->assertInertia(fn (Assert $page) => $page->has('invitations', 2));
    });

    it('reports addresses that cannot be invited, keeping the ones that can', function () {
        $this->actingAs($this->maya)->from('/acme/settings/members')->post('/acme/settings/members/invitations', [
            'emails' => 'good@acme.dev, jon@acme.dev', 'org_role' => 'member', 'projects' => [],
        ])->assertSessionHasErrors('emails')->assertSessionHas('toast.message', 'Invitation sent.');

        expect(Tenancy::crossTenant('inspect', fn () => Invitation::query()->count()))->toBe(1);
    });

    it('refuses an invitation to a project that is not there', function () {
        $this->actingAs($this->maya)->from('/acme/settings/members')->post('/acme/settings/members/invitations', [
            'emails' => 'a@acme.dev', 'org_role' => 'member', 'projects' => [['id' => 'prj_'.str_repeat('0', 26), 'role' => 'viewer']],
        ])->assertSessionHasErrors('projects');
        $this->actingAs($this->maya)->from('/acme/settings/members')->post('/acme/settings/members/invitations', [
            'emails' => 'a@acme.dev', 'org_role' => 'member', 'projects' => [['id' => 'garbage', 'role' => 'viewer']],
        ])->assertSessionHasErrors('projects');
    });

    it('refuses a Member', function () {
        $this->actingAs($this->jon)->post('/acme/settings/members/invitations', ['emails' => 'a@acme.dev', 'org_role' => 'member', 'projects' => []])->assertForbidden();
    });

    it('sends again and withdraws an invitation', function () {
        $id = invite_people(['actor' => Actor::user($this->maya->id, 'Maya', Channel::Dashboard), 'organization' => acme(), 'project' => $this->world['storefront']], ['a@acme.dev'])->created[0]['invitationId'];

        $this->actingAs($this->maya)->post("/acme/settings/members/invitations/{$id}/resend")->assertRedirect()->assertSessionHas('toast.message', 'Invitation sent again.');
        $this->actingAs($this->maya)->delete("/acme/settings/members/invitations/{$id}")->assertRedirect()->assertSessionHas('toast.message', 'Invitation withdrawn.');

        expect(invitation_status($id))->toBe('revoked');
        $this->actingAs($this->maya)->from('/acme/settings/members')->post("/acme/settings/members/invitations/{$id}/resend")->assertSessionHasErrors('invitation');
    });

    it('changes a role and removes a member', function () {
        $membership = Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $this->world['leo']->id)->firstOrFail());

        $this->actingAs($this->maya)->patch("/acme/settings/members/{$membership->id}", ['role' => 'owner'])->assertRedirect()->assertSessionHas('toast.message', 'Role changed.');
        expect(Tenancy::crossTenant('inspect', fn () => $membership->fresh()->role->value))->toBe('owner');

        $this->actingAs($this->maya)->delete("/acme/settings/members/{$membership->id}")->assertRedirect()->assertSessionHas('toast.message', 'Member removed.');
        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $this->world['leo']->id)->count()))->toBe(0);
    });

    it('says why the last owner cannot be demoted or removed', function () {
        $mayas = Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $this->maya->id)->firstOrFail());
        $second = Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $this->world['leo']->id)->firstOrFail());
        $second->forceFill(['role' => 'owner'])->save();
        // Leo, an owner too, demotes Maya; then Maya's stale request to demote Leo would leave no owner.
        $this->actingAs($this->world['leo'])->patch("/acme/settings/members/{$mayas->id}", ['role' => 'member'])->assertRedirect();

        $this->actingAs($this->world['leo'])->from('/acme/settings/members')->patch("/acme/settings/members/{$second->id}", ['role' => 'member'])->assertSessionHasErrors('role');
    });

    it('cannot reach a membership of another organization', function () {
        $theirs = Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $this->world['gina']->id)->firstOrFail());

        $this->actingAs($this->maya)->patch("/acme/settings/members/{$theirs->id}", ['role' => 'member'])->assertNotFound();
        $this->actingAs($this->maya)->delete("/acme/settings/members/{$theirs->id}")->assertNotFound();
    });
});

describe('Projects', function () {
    it('lists the projects with their environments and members', function () {
        $this->actingAs($this->maya)->get('/acme/settings/projects')->assertInertia(fn (Assert $page) => $page
            ->component('organization/Projects')->where('canJoin', true)->has('projects', 2)
            ->where('projects', fn ($projects) => collect($projects)->contains(fn ($p) => $p['key'] === 'storefront' && $p['environments'] === 3 && $p['members'] === 3 && $p['isMember'] === true)));
    });

    it('lets an Org Owner who is not in a project join it as Owner', function () {
        Tenancy::crossTenant('leave', fn () => ProjectMembership::query()->where('user_id', $this->maya->id)->where('project_id', $this->world['checkout']->id)->delete());
        $checkout = PublicId::encode(IdPrefix::Project, $this->world['checkout']->id);

        $this->actingAs($this->maya)->post("/acme/settings/projects/{$checkout}/join")->assertRedirect()->assertSessionHas('toast.message', 'You joined the project as Owner.');

        expect(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->maya->id)->where('project_id', $this->world['checkout']->id)->firstOrFail()->role->value))->toBe('owner');
    });

    it('does not offer joining to a Member, and refuses it', function () {
        $checkout = PublicId::encode(IdPrefix::Project, $this->world['checkout']->id);

        $this->actingAs($this->world['leo'])->get('/acme/settings/projects')->assertInertia(fn (Assert $page) => $page->where('canJoin', false));
        $this->actingAs($this->world['leo'])->post("/acme/settings/projects/{$checkout}/join")->assertForbidden();
    });

    it('cannot join a project of another organization', function () {
        $billing = PublicId::encode(IdPrefix::Project, Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'billing')->firstOrFail()->id));

        $this->actingAs($this->maya)->post("/acme/settings/projects/{$billing}/join")->assertNotFound();
    });
});

it('answers 404 to a person outside the organization on every settings page', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), '{organization}/settings'))
        ->map(fn ($route) => [$route->methods()[0], preg_replace(['/\{organization\}/', '/\{(membership|invitation)\}/', '/\{projectId\}/'], ['acme', str_repeat('0', 26), 'prj_'.str_repeat('0', 26)], $route->uri())]);

    expect($routes->count())->toBeGreaterThan(10);

    foreach ($routes as [$method, $uri]) {
        $this->actingAs($this->world['gina'])->call($method, '/'.$uri, [])->assertNotFound();
    }
});
