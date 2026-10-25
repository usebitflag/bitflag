<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\RequestPasswordReset;
use App\Modules\Identity\Actions\ResetPassword;
use App\Modules\Identity\Data\RequestPasswordResetData;
use App\Modules\Identity\Data\ResetPasswordData;
use App\Modules\Identity\Domain\Exceptions\InvalidResetToken;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\SignIn\PendingSignIn;
use App\Modules\Identity\Queries\PasswordResetToken;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgot password, the "link sent" page, and setting a new password. Asking for a link answers the same whether or not the
 * address has an account.
 */
final class PasswordResetController
{
    public const SESSION_EMAIL = 'reset.email';

    public const RESEND_SECONDS = 60;

    public function forgot(): Response
    {
        return Inertia::render('auth/ForgotPassword');
    }

    public function send(ForgotPasswordRequest $request, RequestPasswordReset $reset): RedirectResponse
    {
        $email = $request->string('email')->toString();

        $reset->handle(new RequestPasswordResetData($email));
        $request->session()->put(self::SESSION_EMAIL, mb_strtolower(trim($email)));

        return redirect('/forgot-password/sent');
    }

    public function resend(Request $request, RequestPasswordReset $reset): RedirectResponse
    {
        $email = $request->session()->get(self::SESSION_EMAIL);

        if (! is_string($email)) {
            return redirect('/forgot-password');
        }

        $reset->handle(new RequestPasswordResetData($email));

        return redirect('/forgot-password/sent');
    }

    public function sent(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get(self::SESSION_EMAIL);

        if (! is_string($email)) {
            return redirect('/forgot-password');
        }

        return Inertia::render('auth/ResetLinkSent', [
            'email' => $email,
            'resendSeconds' => self::RESEND_SECONDS,
        ]);
    }

    public function show(Request $request, PasswordResetToken $tokens, string $token): Response
    {
        $user = $request->query('user');

        try {
            $userId = is_string($user) ? PublicId::decode(IdPrefix::User, $user) : null;
        } catch (ResourceNotFound) {
            $userId = null;
        }

        return Inertia::render('auth/ResetPassword', [
            'token' => $token,
            'user' => is_string($user) ? $user : '',
            'usable' => $userId !== null && $tokens->isUsable($userId, $token),
        ]);
    }

    public function update(ResetPasswordRequest $request, ResetPassword $reset, PendingSignIn $pending, StatefulGuard $guard): RedirectResponse
    {
        try {
            $userId = PublicId::decode(IdPrefix::User, $request->string('user')->toString());

            $result = $reset->handle(new ResetPasswordData(
                $userId,
                $request->string('token')->toString(),
                $request->string('password')->toString(),
                $request->ip(),
                $request->userAgent(),
            ));
        } catch (InvalidResetToken|ResourceNotFound $e) {
            return back()->withErrors(['token' => (new InvalidResetToken)->getMessage()]);
        }

        $toast = ['message' => 'Your password was updated.', 'tone' => 'neutral'];

        if (! $result->canSignIn) {
            return redirect('/signin')->with('toast', $toast);
        }

        // A reset never skips the second factor: the person is parked as pending, not signed in.
        if ($result->secondFactorRequired) {
            $pending->begin($request->session(), $result->user->id);

            return redirect('/two-factor-challenge');
        }

        $guard->login($result->user);
        $request->session()->regenerate();

        return redirect('/')->with('toast', $toast);
    }
}
