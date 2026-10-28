<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support\OAuth;

/**
 * One provider's authorization code flow with PKCE.
 */
interface OAuthClient
{
    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string;

    /**
     * Trades the code for the person's profile.
     *
     * @throws OAuthFailed
     */
    public function profile(string $code, string $redirectUri, string $codeVerifier): OAuthProfile;
}
