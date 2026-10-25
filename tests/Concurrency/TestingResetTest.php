<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyTestingClock;
use App\Http\Middleware\RequireTestingToken;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Resetting rebuilds the schema, which commits, so this cannot run inside the transaction the feature tests use.
it('rebuilds the demo world and releases the clock', function () {
    config(['testing.e2e' => true, 'testing.token' => 'e2e-test-token']);
    Route::middleware('web')->group(base_path('routes/testing.php'));
    Route::getRoutes()->refreshNameLookups();

    Cache::forever(ApplyTestingClock::CACHE_KEY, '2030-01-02T03:04:05+00:00');

    $this->withHeader(RequireTestingToken::HEADER, 'e2e-test-token')->postJson('/_testing/reset')
        ->assertOk()->assertJsonPath('data.reset', true);

    expect(User::query()->where('email', 'maya@acme.dev')->exists())->toBeTrue()
        ->and(Tenancy::crossTenant('reset check', fn () => Project::query()->count()))->toBe(4)
        ->and(Cache::has(ApplyTestingClock::CACHE_KEY))->toBeFalse();
});
