<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The only stage that signs anyone in, reached only when no second factor is owed. There is no "remember me": a session
 * already lasts up to thirty days.
 */
final class CompleteSignIn
{
    public function __construct(private readonly StatefulGuard $guard) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = SignInStage::user($request);

        if ($user !== null) {
            $this->guard->login($user, false);
            $request->session()->put(PostSignIn::CHOOSE_ORGANIZATION, true);

            $device = SignInStage::deviceToken($request);

            if ($device !== null) {
                Cookie::queue(SignInCookies::device($request, $device));
            }
        }

        return $next($request);
    }
}
