<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\OrganizationMfaPolicy;
use App\Modules\Identity\Domain\SessionPolicyLookup;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Organizations\Queries\OrganizationSecurityPolicies;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
});

/** A person who belongs to two organizations. */
function two_organizations(): array
{
    $first = person(OrgRole::Member, ProjectRole::Developer);
    $second = person(null, null);
    OrganizationMembership::factory()->create(['organization_id' => $second['organization']->id, 'user_id' => $first['user']->id, 'role' => OrgRole::Owner]);
    ProjectMembership::factory()->create(['organization_id' => $second['organization']->id, 'project_id' => $second['project']->id, 'user_id' => $first['user']->id, 'role' => ProjectRole::Owner]);

    return [$first, $second];
}

it('lists the organizations with the person\'s role and project count', function () {
    [$first, $second] = two_organizations();

    $this->actingAs($first['user'])->get('/organizations/choose')->assertInertia(fn (Assert $page) => $page
        ->component('auth/ChooseOrganization')
        ->has('organizations', 2)
        ->where('remember', false)
        ->where('organizations', fn ($organizations) => collect($organizations)->contains(fn ($o) => $o['slug'] === $second['organization']->slug && $o['role'] === 'owner' && $o['projectCount'] === 1)));
});

it('never lists an organization the person does not belong to', function () {
    [$first] = two_organizations();
    $stranger = person(OrgRole::Owner, null);

    $body = $this->actingAs($first['user'])->get('/organizations/choose')->getContent();

    expect($body)->not->toContain($stranger['organization']->slug);
});

it('sends someone with no organization to the site root', function () {
    $this->actingAs(User::factory()->create())->get('/organizations/choose')->assertRedirect('/');
});

it('opens the chosen organization and remembers the choice if asked', function () {
    [$first, $second] = two_organizations();

    $this->actingAs($first['user'])->post('/organizations/choose', ['organization' => $second['organization']->slug, 'remember' => true])
        ->assertRedirect(route('organization.home', ['organization' => $second['organization']->slug]));

    $user = $first['user']->fresh();

    expect($user->last_organization_id)->toBe($second['organization']->id)
        ->and($user->remember_org_choice)->toBeTrue();
});

it('forgets the remembered project and environment when moving to another organization', function () {
    [$first, $second] = two_organizations();
    $first['user']->forceFill(['last_organization_id' => $first['organization']->id, 'last_project_id' => $first['project']->id, 'last_environment_id' => $first['environment']->id])->save();

    $this->actingAs($first['user'])->post('/organizations/choose', ['organization' => $second['organization']->slug]);

    $user = $first['user']->fresh();
    expect($user->last_project_id)->toBeNull()->and($user->last_environment_id)->toBeNull()->and($user->remember_org_choice)->toBeFalse();
});

it('keeps the project when choosing the organization already in use', function () {
    [$first] = two_organizations();
    $first['user']->forceFill(['last_organization_id' => $first['organization']->id, 'last_project_id' => $first['project']->id])->save();

    $this->actingAs($first['user'])->post('/organizations/choose', ['organization' => $first['organization']->slug]);

    expect($first['user']->fresh()->last_project_id)->toBe($first['project']->id);
});

it('answers 404 for an organization the person is not in, or that does not exist', function () {
    [$first] = two_organizations();
    $stranger = person(OrgRole::Owner, null);

    $this->actingAs($first['user'])->post('/organizations/choose', ['organization' => $stranger['organization']->slug])->assertNotFound();
    $this->actingAs($first['user'])->post('/organizations/choose', ['organization' => 'nothing-here'])->assertNotFound();
    expect($first['user']->fresh()->last_organization_id)->toBeNull();
});

it('offers the chooser right after signing in to a person in several organizations', function () {
    [$first] = two_organizations();
    $first['user']->forceFill(['email' => 'maya@acme.dev', 'password' => 'correct horse'])->save();

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/');
    $this->get('/')->assertRedirect(route('organizations.choose'));
});

it('offers the chooser once, not on every visit to the site root', function () {
    [$first] = two_organizations();
    $first['user']->forceFill(['email' => 'maya@acme.dev', 'password' => 'correct horse'])->save();

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    $this->get('/')->assertRedirect(route('organizations.choose'));

    expect($this->get('/')->headers->get('Location'))->not->toContain('organizations/choose');
});

it('skips the chooser when the person asked it to remember their choice', function () {
    [$first] = two_organizations();
    $first['user']->forceFill(['email' => 'maya@acme.dev', 'password' => 'correct horse', 'remember_org_choice' => true, 'last_organization_id' => $first['organization']->id])->save();

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    expect($this->get('/')->headers->get('Location'))->not->toContain('organizations/choose');
});

it('does not offer the chooser to a person in one organization', function () {
    $p = person(OrgRole::Member, ProjectRole::Developer);
    $p['user']->forceFill(['email' => 'maya@acme.dev', 'password' => 'correct horse'])->save();

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    expect($this->get('/')->headers->get('Location'))->not->toContain('organizations/choose');
});

it('shares the role and project count of each organization in the switcher', function () {
    [$first, $second] = two_organizations();
    $url = route('project.overview', ['organization' => $first['organization']->slug, 'project' => $first['project']->key, 'environment' => $first['environment']->key]);

    $this->actingAs($first['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('switcher', fn (Assert $reload) => $reload
            ->has('switcher.organizations', 2)
            ->where('switcher.organizations', fn ($organizations) => collect($organizations)->contains(fn ($o) => $o['slug'] === $second['organization']->slug && $o['role'] === 'owner' && $o['projectCount'] === 1)
                && collect($organizations)->contains(fn ($o) => $o['slug'] === $first['organization']->slug && $o['role'] === 'member' && $o['projectCount'] === 1))));
});

describe('what an organization\'s settings mean for its people', function () {
    it('names the organizations that require two-factor, for the account screens', function () {
        [$first, $second] = two_organizations();
        Organization::query()->whereKey($second['organization']->id)->update(['require_mfa' => true]);

        expect(app(OrganizationMfaPolicy::class)->organizationsRequiringMfa($first['user']->id))->toBe([$second['organization']->name])
            ->and(app(OrganizationMfaPolicy::class)->organizationsRequiringMfa(User::factory()->create()->id))->toBe([]);
    });

    it('reads the idle limit an organization chose by its URL name', function () {
        $p = person(OrgRole::Owner, null);
        Organization::query()->whereKey($p['organization']->id)->update(['session_idle_timeout_minutes' => 720]);

        expect(app(SessionPolicyLookup::class)->idleMinutesFor($p['organization']->slug))->toBe(720)
            ->and(app(SessionPolicyLookup::class)->idleMinutesFor('unknown-slug'))->toBeNull()
            ->and(app(OrganizationSecurityPolicies::class)->idleMinutesFor($p['organization']->slug))->toBe(720);
    });

    it('ends a session at the organization\'s idle limit, only while that organization is open', function () {
        $p = person(OrgRole::Owner, null);
        Organization::query()->whereKey($p['organization']->id)->update(['session_idle_timeout_minutes' => 60]);

        $this->actingAs($p['user'])->get('/account/security')->assertOk();
        $this->travel(2)->hours();

        $this->actingAs($p['user'])->get('/account/security')->assertOk();
        $this->travel(2)->hours();
        $this->actingAs($p['user'])->getJson('/'.$p['organization']->slug)->assertStatus(409)
            ->assertJsonPath('error.message', 'Your session ended after 1 hour of inactivity.');
    });

    it('stops a person turning two-factor off while their organization requires it', function () {
        $p = person(OrgRole::Member, null);
        $p['user']->forceFill(['password' => 'correct horse battery'])->save();
        enroll_two_factor($p['user']);
        Organization::query()->whereKey($p['organization']->id)->update(['require_mfa' => true]);

        $response = $this->actingAs($p['user'])->deleteJson('/account/two-factor', ['password' => 'correct horse battery', 'code' => totp_now()]);

        invalid($response, 'two_factor', $p['organization']->name.' requires two-factor sign-in.');
    });
});
