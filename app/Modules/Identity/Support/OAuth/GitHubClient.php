<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support\OAuth;

use App\Modules\Identity\Data\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GitHub: the numeric user id is the stable id, and only the primary email that GitHub has verified is trusted. A person
 * can hold unverified addresses on GitHub, and the profile's public email is not proof of anything.
 */
final class GitHubClient implements OAuthClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'read:user user:email',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function profile(string $code, string $redirectUri, string $codeVerifier): OAuthProfile
    {
        try {
            $token = Http::asForm()->acceptJson()->timeout(10)->post('https://github.com/login/oauth/access_token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ]);

            $accessToken = $token->json('access_token');

            if ($token->failed() || ! is_string($accessToken) || $accessToken === '') {
                throw new OAuthFailed('GitHub did not accept the sign-in. Try again.');
            }

            $api = Http::withToken($accessToken)->acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(10);
            $user = $api->get('https://api.github.com/user');
            $emails = $api->get('https://api.github.com/user/emails');
        } catch (ConnectionException) {
            throw new OAuthFailed('GitHub could not be reached. Try again.');
        }

        $id = $user->json('id');

        if ($user->failed() || ! is_int($id)) {
            throw new OAuthFailed('GitHub did not say who you are. Try again.');
        }

        $name = $user->json('name');
        $login = $user->json('login');

        return new OAuthProfile(
            SocialProvider::GitHub,
            (string) $id,
            $this->verifiedPrimaryEmail($emails->successful() ? $emails->json() : []),
            is_string($name) && $name !== '' ? $name : (is_string($login) ? $login : ''),
        );
    }

    private function verifiedPrimaryEmail(mixed $emails): ?string
    {
        foreach (is_array($emails) ? $emails : [] as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
                return mb_strtolower(trim($entry['email']));
            }
        }

        return null;
    }
}
