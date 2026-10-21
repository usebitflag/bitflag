<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use App\Http\Middleware\ApplyTestingClock;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Puts the database back to the demo seed and unfreezes the clock, so a spec starts from a known state.
 */
final class ResetController
{
    public function __invoke(): JsonResponse
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => DemoSeeder::class, '--force' => true]);
        Cache::forget(ApplyTestingClock::CACHE_KEY);

        return response()->json(['data' => ['reset' => true]]);
    }
}
