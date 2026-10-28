<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stops a sign-in that still owes a second factor and parks it in the session (see PendingSignIn).
 */
final class RedirectIfTwoFactorRequired
{
    public function __construct(private readonly PendingSignIn $pending) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = SignInStage::user($request);

        if ($user === null || $request->attributes->get('bitflag.sign_in.second_factor') !== true) {
            return $next($request);
        }

        $this->pending->begin($request->session(), $user->id);

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => true])
            : redirect('/two-factor-challenge');
    }
}
