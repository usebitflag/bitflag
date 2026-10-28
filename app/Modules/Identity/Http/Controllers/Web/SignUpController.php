<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\RegisterAccount;
use App\Modules\Identity\Data\RegisterAccountData;
use App\Modules\Identity\Http\Requests\SignUpRequest;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sign-up screen and its submission. A new address is signed in and an existing one is not, but both go to the same
 * "check your email" page.
 */
final class SignUpController
{
    public const SESSION_EMAIL = 'signup.email';

    public function create(): Response
    {
        return Inertia::render('auth/SignUp');
    }

    public function store(SignUpRequest $request, RegisterAccount $register, StatefulGuard $guard): RedirectResponse
    {
        $name = $request->string('name')->trim()->toString();
        $email = $request->string('email')->toString();

        $result = $register->handle(new RegisterAccountData(
            $name === '' ? null : $name,
            $email,
            $request->string('password')->toString(),
        ));

        if ($result->createdUser !== null) {
            $guard->login($result->createdUser);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_EMAIL, mb_strtolower(trim($email)));

        return redirect('/signup/check-email');
    }
}
