<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyTestingClock;
use App\Http\Middleware\RequireTestingToken;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['testing.e2e' => true, 'testing.token' => 'e2e-test-token']);

    // The routes load at boot only when TESTING_E2E is set, so load them here, the same way bootstrap/app.php does.
    Route::middleware('web')->group(base_path('routes/testing.php'));
    Route::getRoutes()->refreshNameLookups();
    Route::prependMiddlewareToGroup('web', ApplyTestingClock::class);

    $this->withHeader(RequireTestingToken::HEADER, 'e2e-test-token');
});

afterEach(function () {
    Carbon::setTestNow();
    Cache::forget(ApplyTestingClock::CACHE_KEY);
});

it('answers 404 without the token or with a wrong one', function (string $path) {
    $this->withHeader(RequireTestingToken::HEADER, 'wrong')->postJson($path, ['email' => 'maya@acme.dev'])
        ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');

    $this->withoutHeader(RequireTestingToken::HEADER)->postJson($path)->assertNotFound();
})->with(['/_testing/login', '/_testing/clock', '/_testing/fail', '/_testing/reset']);

it('answers 404 when no token is configured', function () {
    config(['testing.token' => null]);

    $this->withHeader(RequireTestingToken::HEADER, '')->postJson('/_testing/clock')->assertNotFound();
});

it('signs a seeded person in without the form', function () {
    $this->seed(DemoSeeder::class);

    $this->postJson('/_testing/login', ['email' => 'maya@acme.dev'])->assertOk()->assertJsonPath('data.email', 'maya@acme.dev');

    $this->get('/')->assertRedirect('/acme/storefront/production/overview');
});

it('rejects a login for someone who is not seeded', function () {
    $this->postJson('/_testing/login', ['email' => 'nobody@acme.dev'])->assertNotFound();
    $this->assertGuest('web');
});

it('freezes the clock across requests and releases it', function () {
    $this->postJson('/_testing/clock', ['now' => '2030-01-02T03:04:05Z'])->assertOk()->assertJsonPath('data.frozen_at', '2030-01-02T03:04:05+00:00');

    $this->getJson('/_testing/fail');
    expect(Carbon::now()->toIso8601String())->toBe('2030-01-02T03:04:05+00:00');

    $this->postJson('/_testing/clock', ['now' => null])->assertOk()->assertJsonPath('data.frozen_at', null);
    $this->getJson('/_testing/fail');
    expect(Carbon::hasTestNow())->toBeFalse();
});

it('rejects a clock value that is not a date', function () {
    $this->postJson('/_testing/clock', ['now' => 'tomorrow-ish'])->assertUnprocessable();
});

it('raises a forced failure the error screen can show', function () {
    $response = $this->post('/_testing/fail', headers: ['Accept' => 'text/html'])->assertStatus(500);

    expect($response->headers->get('X-Request-Id'))->toStartWith('req_');
});
