<?php

declare(strict_types=1);

use App\Shared\Capabilities\Capability;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->world = portalWorld());

it('shares the person, scope, environments and navigation on a portal page', function () {
    $this->actingAs($this->world['maya'])->get('/acme/storefront/production/overview')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.name', 'Maya Santos')
        ->where('auth.user.email', 'maya@acme.dev')
        ->where('auth.user.initials', 'MS')
        ->where('scope.organization.slug', 'acme')
        ->where('scope.project.key', 'storefront')
        ->where('scope.environment.key', 'production')
        ->where('scope.environment.revision', null)
        ->where('scope.projectRole', 'owner')
        ->where('scope.orgRole', 'owner')
        ->where('environments.0.key', 'development')
        ->where('environments.2.key', 'production')
        ->has('environments', 3)
        ->where('navigation.0.key', 'overview')
        ->where('navigation.0.label', 'Overview')
        ->where('navigation.0.href', '/acme/storefront/production/overview')
        ->where('navigation.0.active', true)
        ->where('navigation.0.count', null)
        ->where('flash.toast', null));
});

it('shares public identifiers and never a model or a secret', function () {
    $response = $this->actingAs($this->world['maya'])->get('/acme/storefront/production/overview');
    $props = $response->viewData('page')['props'];

    expect($props['auth']['user']['id'])->toStartWith('usr_')
        ->and($props['scope']['organization']['id'])->toStartWith('org_')
        ->and($props['scope']['project']['id'])->toStartWith('prj_')
        ->and($props['scope']['environment']['id'])->toStartWith('env_');

    $json = json_encode($props, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('password')->not->toContain('remember_token')
        ->and($json)->not->toMatch('/"id":"[0-9a-z]{26}"/');
});

it('computes the capability map for each role', function (string $person, array $allowed) {
    $this->actingAs($this->world[$person])->get('/acme/storefront/production/overview')->assertInertia(function (Assert $page) use ($allowed) {
        $can = $page->toArray()['props']['can'];

        foreach (Capability::cases() as $capability) {
            expect($can[$capability->value])->toBe(in_array($capability->value, $allowed, true), $capability->value);
        }
    });
})->with([
    'viewer' => ['ana', ['project.read', 'audit.read', 'audit.export', 'org.read']],
    'publisher' => ['jon', ['project.read', 'flags.draft.write', 'segments.write', 'environment.publish', 'environment.publish.protected', 'changeset.approve', 'experiments.manage', 'audit.read', 'sensitive.read', 'changeset.submit', 'audit.export', 'org.read']],
]);

it('gives an owner every project capability and the organization capabilities of an Org Owner', function () {
    $this->actingAs($this->world['maya'])->get('/acme/storefront/production/overview')->assertInertia(function (Assert $page) {
        $can = $page->toArray()['props']['can'];

        expect(array_filter($can))->toHaveCount(count(Capability::cases()));
    });
});

it('shares an empty capability object outside the portal', function () {
    $this->actingAs($this->world['maya'])->get('/signin')->assertRedirect('/');

    $html = $this->get('/_dev/ui')->assertOk()->getContent();

    expect($html)->toContain('"can":{}');
});

it('shares a flashed toast once', function () {
    $this->actingAs($this->world['maya'])->withSession(['toast' => ['message' => 'Rollout updated in production', 'tone' => 'accent']])
        ->get('/acme/storefront/production/overview')->assertInertia(fn (Assert $page) => $page
        ->where('flash.toast.message', 'Rollout updated in production')
        ->where('flash.toast.tone', 'accent'));
});

it('loads the switcher only when asked for it', function () {
    $this->actingAs($this->world['maya'])->get('/acme/storefront/production/overview')->assertInertia(fn (Assert $page) => $page->missing('switcher'));

    $this->actingAs($this->world['maya'])->get('/acme/storefront/production/overview')->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('switcher', fn (Assert $reload) => $reload
            ->where('switcher.organizations.0.slug', 'acme')
            ->where('switcher.organizations.0.projects.0.key', 'checkout-api')
            ->where('switcher.organizations.0.projects.1.key', 'storefront')
            ->has('switcher.organizations', 1)));
});

it('lists only the projects a person can open in the switcher', function () {
    $this->actingAs($this->world['ana'])->get('/acme/storefront/production/overview')->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('switcher', fn (Assert $reload) => $reload->has('switcher.organizations.0.projects', 1)->where('switcher.organizations.0.projects.0.key', 'storefront')));
});
