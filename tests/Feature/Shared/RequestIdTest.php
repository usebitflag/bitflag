<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeVite;

const REQUEST_ID_PATTERN = '/^req_[0-7][0-9a-hjkmnp-tv-z]{25}$/';

it('adds a request ID header to every response', function () {
    $this->get('/up')->assertHeader('X-Request-Id');

    expect($this->get('/up')->headers->get('X-Request-Id'))->toMatch(REQUEST_ID_PATTERN);
});

it('ignores a request ID sent by the client', function () {
    $response = $this->get('/up', ['X-Request-Id' => 'req_attacker-chosen']);

    expect($response->headers->get('X-Request-Id'))
        ->toMatch(REQUEST_ID_PATTERN)
        ->not->toBe('req_attacker-chosen');
});

it('gives each request its own ID', function () {
    $first = $this->get('/up')->headers->get('X-Request-Id');
    $second = $this->get('/up')->headers->get('X-Request-Id');

    expect($first)->not->toBe($second);
});

it('adds the request ID to the log context', function () {
    $id = $this->get('/up')->headers->get('X-Request-Id');

    expect(Log::sharedContext())->toHaveKey('request_id', $id);
});

it('shares the request ID with the portal', function () {
    FakeVite::install();
    config(['inertia.testing.ensure_pages_exist' => false]);
    Route::middleware('web')->get('/_probe', fn () => Inertia::render('Probe'));

    $response = $this->get('/_probe');
    $id = $response->headers->get('X-Request-Id');

    $response->assertInertia(fn (Assert $page) => $page->where('requestId', $id));
});
