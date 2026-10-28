<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use App\Http\Middleware\ApplyTestingClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Freezes the server clock at a given instant, or releases it when no instant is given.
 */
final class ClockController
{
    /**
     * The instant the server believes it is, frozen or not, so a spec can compute time-based codes from it.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['now' => Carbon::now()->utc()->toIso8601String()]]);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['now' => ['nullable', 'date']]);

        $now = $request->input('now');

        if (! is_string($now) || $now === '') {
            Cache::forget(ApplyTestingClock::CACHE_KEY);

            return response()->json(['data' => ['frozen_at' => null]]);
        }

        $instant = Carbon::parse($now)->utc()->toIso8601String();
        Cache::forever(ApplyTestingClock::CACHE_KEY, $instant);

        return response()->json(['data' => ['frozen_at' => $instant]]);
    }
}
