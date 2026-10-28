<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\SignInWithProvider;
use App\Modules\Identity\Data\SignInWithProviderData;
use App\Modules\Identity\Data\SocialProvider;
use App\Modules\Identity\Data\SocialSignInOutcome;
use App\Modules\Identity\Http\SignIn\PendingSignIn;
use App\Modules\Identity\Http\SignIn\PostSignIn;
use App\Modules\Identity\Http\SignIn\SignInCookies;
use App\Modules\Identity\Support\OAuth\OAuthFailed;
use App\Modules\Identity\Support\OAuth\OAuthProviders;
use App\Modules\Identity\Support\OAuth\OAuthState;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Signing up and in with Google or GitHub. One route sends the browser to the provider, the other receives it back. The
 * sign-up and sign-in buttons are the same thing: a verified email with no account makes one.
 */
final class SocialAuthController
{
    public function redirect(Request $request, OAuthProviders $providers, OAuthState $state, string $provider): RedirectResponse
    {
        $provider = SocialProvider::tryFrom($provider) ?? abort(404);

        if (! $providers->configured($provider)) {
            return $this->back("Sign-in with {$provider->label()} is not available.");
        }

        $trip = $state->begin($request->session(), $provider);

        return redirect()->away($providers->client($provider)->authorizationUrl($this->callbackUrl($provider), $trip['state'], $trip['challenge']));
    }

    public function callback(
        Request $request,
        OAuthProviders $providers,
        OAuthState $state,
        SignInWithProvider $signIn,
        PendingSignIn $pending,
        StatefulGuard $guard,
        string $provider,
    ): RedirectResponse {
        $provider = SocialProvider::tryFrom($provider) ?? abort(404);

        $returnedState = $request->query('state');
        $code = $request->query('code');
        $verifier = is_string($returnedState) ? $state->consume($request->session(), $provider, $returnedState) : null;

        if ($request->query('error') !== null) {
            return $this->back("{$provider->label()} sign-in was cancelled.");
        }

        if ($verifier === null || ! is_string($code) || $code === '' || ! $providers->configured($provider)) {
            return $this->back('That sign-in link has expired. Try again.');
        }

        try {
            $profile = $providers->client($provider)->profile($code, $this->callbackUrl($provider), $verifier);
        } catch (OAuthFailed $failure) {
            return $this->back($failure->getMessage());
        }

        $result = $signIn->handle(new SignInWithProviderData(
            $profile,
            $request->ip(),
            $request->userAgent(),
            SignInCookies::read($request, SignInCookies::TRUSTED_BROWSER),
            SignInCookies::read($request, SignInCookies::DEVICE),
        ));

        switch ($result->outcome) {
            case SocialSignInOutcome::EmailNotVerified:
                return $this->back("{$provider->label()} did not share an email address it has verified, so we cannot sign you in with it.");

            case SocialSignInOutcome::AccountExists:
                return $this->back('An account with '.CheckEmailController::mask((string) $result->email).' already exists. Sign in with your email and password.');

            case SocialSignInOutcome::Refused:
                return $this->back("That {$provider->label()} account cannot sign in.");

            case SocialSignInOutcome::TwoFactorRequired:
                $pending->begin($request->session(), (string) $result->user?->id);

                return redirect('/two-factor-challenge');

            case SocialSignInOutcome::Authenticated:
            case SocialSignInOutcome::Created:
                if ($result->user === null) {
                    return $this->back('Sign-in did not finish. Try again.');
                }

                $guard->login($result->user, false);
                $request->session()->regenerate();
                $request->session()->put(PostSignIn::CHOOSE_ORGANIZATION, true);

                if ($result->deviceToken !== null) {
                    Cookie::queue(SignInCookies::device($request, $result->deviceToken));
                }

                return redirect()->intended('/');
        }
    }

    private function callbackUrl(SocialProvider $provider): string
    {
        return url("/auth/{$provider->value}/callback");
    }

    private function back(string $message): RedirectResponse
    {
        return redirect('/signin')->withErrors(['email' => $message]);
    }
}
