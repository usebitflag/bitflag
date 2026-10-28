<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Clock\ClockInterface;

/**
 * Stops a sign-in that still owes a second factor. The session keeps only who is pending, for ten minutes; the person is
 * not signed in, so every route that needs a signed-in person treats them as a guest until the code is accepted.
 */
final class RedirectIfTwoFactorRequired
{
    public const SESSION_KEY = 'login.pending';

    public const PENDING_MINUTES = 10;

    public function __construct(private readonly ClockInterface $clock) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = SignInStage::user($request);

        if ($user === null || $request->attributes->get('bitflag.sign_in.second_factor') !== true) {
            return $next($request);
        }

        // A new session id, so an id known before the password was typed is worthless afterwards.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'remember' => false,
            'expires_at' => $this->clock->now()->modify('+'.self::PENDING_MINUTES.' minutes')->format(DATE_ATOM),
            'attempts' => 0,
        ]);

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => true])
            : redirect('/two-factor-challenge');
    }
}
