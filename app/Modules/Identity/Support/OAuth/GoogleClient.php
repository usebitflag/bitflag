<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support\OAuth;

use App\Modules\Identity\Data\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google, through OpenID Connect: the `sub` is the stable user id, and `email_verified` says whether to trust the email.
 */
final class GoogleClient implements OAuthClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    public function profile(string $code, string $redirectUri, string $codeVerifier): OAuthProfile
    {
        try {
            $token = Http::asForm()->acceptJson()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code_verifier' => $codeVerifier,
            ]);

            $accessToken = $token->json('access_token');

            if ($token->failed() || ! is_string($accessToken) || $accessToken === '') {
                throw new OAuthFailed('Google did not accept the sign-in. Try again.');
            }

            $user = Http::withToken($accessToken)->acceptJson()->timeout(10)->get('https://openidconnect.googleapis.com/v1/userinfo');
        } catch (ConnectionException) {
            throw new OAuthFailed('Google could not be reached. Try again.');
        }

        $id = $user->json('sub');
        $email = $user->json('email');

        if ($user->failed() || ! is_string($id) || $id === '') {
            throw new OAuthFailed('Google did not say who you are. Try again.');
        }

        $verified = $user->json('email_verified');
        $name = $user->json('name');

        return new OAuthProfile(
            SocialProvider::Google,
            $id,
            is_string($email) && ($verified === true || $verified === 'true') ? mb_strtolower(trim($email)) : null,
            is_string($name) ? $name : '',
        );
    }
}
