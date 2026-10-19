<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['inertia.testing.ensure_pages_exist' => false]);

    Route::middleware('web')->get('/_probe', fn () => Inertia::render('Probe'));
});

it('shares an empty scope and no user before anyone is signed in', function () {
    $this->get('/_probe')->assertInertia(fn (Assert $page) => $page
        ->component('Probe')
        ->where('auth.user', null)
        ->where('scope.organization', null)
        ->where('scope.project', null)
        ->where('scope.environment', null)
        ->where('scope.projectRole', null)
        ->where('scope.orgRole', null)
        ->where('environments', [])
        ->where('navigation', [])
        ->where('flash.toast', null));
});

it('shares the capability map as an object, not a list', function () {
    $html = $this->get('/_probe')->getContent();

    expect($html)->toContain('"can":{}');
});
