<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Http\Middleware\AssignRequestId;
use App\Modules\Identity\Domain\SessionPolicy;
use App\Modules\Identity\Domain\SessionPolicyLookup;
use App\Shared\Errors\ErrorEnvelope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a signed-in session that has been idle too long or has lived too long (see SessionPolicy). The organization in the
 * address, if there is one, may shorten the idle limit; a session that spans organizations is held to each one's limit
 * only while that organization is open. A page request is sent to sign in and, after signing in, back to where it was.
 * A background or Inertia request gets a 409 SESSION_EXPIRED that the page answers with a dialog, without leaving the page.
 * The times live in the session itself, so they hold whichever session driver is in use.
 */
final class EnforceSessionPolicy
{
    public const STARTED = 'auth.started_at';

    public const LAST_SEEN = 'auth.last_seen_at';

    public function __construct(
        private readonly SessionPolicyLookup $organizations,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if (! $guard->check() || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = $this->clock->now()->getTimestamp();
        $startedAt = $session->get(self::STARTED);
        $lastSeen = $session->get(self::LAST_SEEN);

        if (is_int($startedAt) && is_int($lastSeen)) {
            $slug = $request->route('organization');
            $expiry = SessionPolicy::expiry(
                $startedAt,
                $lastSeen,
                $now,
                is_string($slug) ? $this->organizations->idleMinutesFor($slug) : null,
            );

            if ($expiry !== null) {
                return $this->end($request, $expiry);
            }
        }

        // The clock starts when the session is first seen signed in, however the person signed in.
        $session->put(self::STARTED, is_int($startedAt) ? $startedAt : $now);
        $session->put(self::LAST_SEEN, $now);

        return $next($request);
    }

    /**
     * @param  array{reason: string, limit_minutes: int}  $expiry
     */
    private function end(Request $request, array $expiry): Response
    {
        $wantsDialog = $request->header('X-Inertia') !== null || $request->expectsJson();
        $intended = $request->isMethod('GET') ? $request->fullUrl() : null;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($wantsDialog) {
            $requestId = $request->attributes->get(AssignRequestId::ATTRIBUTE);

            return response()->json((new ErrorEnvelope(
                'SESSION_EXPIRED',
                SessionPolicy::message($expiry['reason'], $expiry['limit_minutes']),
                is_string($requestId) ? $requestId : null,
                $expiry,
            ))->toArray(), 409);
        }

        if ($intended !== null) {
            $request->session()->put('url.intended', $intended);
        }

        return redirect('/signin')->with('toast', ['message' => SessionPolicy::message($expiry['reason'], $expiry['limit_minutes']), 'tone' => 'neutral']);
    }
}
