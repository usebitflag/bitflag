<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Shared\Errors\ResourceNotFound;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the end-to-end test routes. They answer only to a request that carries the configured token; any other
 * request gets the ordinary 404, so the routes cannot be told apart from a path that does not exist.
 */
final class RequireTestingToken
{
    public const HEADER = 'X-Testing-Token';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = Config::get('testing.token');
        $given = $request->header(self::HEADER);

        if (! is_string($expected) || $expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            throw new ResourceNotFound;
        }

        return $next($request);
    }
}
