<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs a seeded person in without the form, for specs that are not about signing in.
 */
final class LoginController
{
    public function __invoke(Request $request): JsonResponse
    {
        $email = $request->string('email')->toString();

        /** @var StatefulGuard $guard */
        $guard = Auth::guard('web');
        $user = $guard->getProvider()->retrieveByCredentials(['email' => $email]);

        if ($user === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'No such demo user.']], 404);
        }

        $guard->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => ['email' => $email]]);
    }
}
