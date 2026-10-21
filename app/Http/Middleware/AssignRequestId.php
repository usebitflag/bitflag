<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Shared\Ids\IdPrefix;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an identifier of the form req_{ulid}. A value sent by the client is ignored, so request IDs
 * cannot be forged or used to inject log content. The ID is returned in X-Request-Id, added to the log context and
 * shared with the portal so error screens can show it.
 */
final class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = IdPrefix::Request->value.strtolower((string) Str::ulid());

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Log::shareContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
