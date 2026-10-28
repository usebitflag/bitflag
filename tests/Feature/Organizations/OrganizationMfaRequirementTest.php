<?php

declare(strict_types=1);

use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Organizations\Models\Organization;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->world = portalWorld();
    $this->url = '/acme/storefront/production/overview';
    $this->maya = $this->world['maya'];
});

function require_mfa(bool $on = true): void
{
    Organization::query()->where('slug', 'acme')->update(['require_mfa' => $on]);
}

it('lets everyone work when the organization does not require two-factor', function () {
    $this->actingAs($this->maya)->get($this->url)->assertOk();
});

it('sends a member without two-factor to account security, naming the organization', function () {
    require_mfa();

    $this->actingAs($this->maya)->get($this->url)->assertRedirect('/account/security?required_by=Acme');
});

it('lets a member with two-factor through', function () {
    require_mfa();
    enroll_two_factor($this->maya);

    $this->actingAs($this->maya)->get($this->url)->assertOk();
});

it('does not count an enrollment that was never confirmed', function () {
    require_mfa();
    enroll_two_factor($this->maya);
    MfaFactor::query()->update(['confirmed_at' => null]);

    $this->actingAs($this->maya)->get($this->url)->assertRedirect();
});

it('stops every portal page of the organization, not only the overview', function () {
    require_mfa();

    foreach (['/acme', '/acme/storefront', '/acme/settings'] as $uri) {
        $this->actingAs($this->maya)->get($uri)->assertRedirect('/account/security?required_by=Acme');
    }
});

it('always leaves account pages reachable, so the person can set it up', function () {
    require_mfa();

    $this->actingAs($this->maya)->get('/account/security?required_by=Acme')->assertOk()->assertInertia(fn ($page) => $page->where('requiredBy', 'Acme'));
    $this->actingAs($this->maya)->postJson('/account/two-factor', ['password' => 'password'])->assertOk();
});

it('answers a background request that is not Inertia with 403 MFA_REQUIRED', function () {
    require_mfa();

    $this->actingAs($this->maya)->getJson($this->url)->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
});

it('leaves other organizations alone', function () {
    require_mfa();
    $other = person(OrgRole::Member, ProjectRole::Developer);

    $this->actingAs($other['user'])->get(route('project.overview', ['organization' => $other['organization']->slug, 'project' => $other['project']->key, 'environment' => $other['environment']->key]))->assertOk();
});

it('does not turn anyone away before they have two-factor set up, only after the setting is on', function () {
    $this->actingAs($this->maya)->get($this->url)->assertOk();

    require_mfa();
    $this->actingAs($this->maya)->get($this->url)->assertRedirect();

    require_mfa(false);
    $this->actingAs($this->maya)->get($this->url)->assertOk();
});
