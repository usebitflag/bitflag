<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the end-to-end suite freeze the server clock across requests. The frozen time is kept in the cache by the
 * testing clock route; the application clock follows Carbon's test time. Registered only when the test routes load.
 */
final class ApplyTestingClock
{
    public const CACHE_KEY = 'testing.clock';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $frozen = Cache::get(self::CACHE_KEY);

        Carbon::setTestNow(is_string($frozen) ? Carbon::parse($frozen) : null);

        return $next($request);
    }
}
