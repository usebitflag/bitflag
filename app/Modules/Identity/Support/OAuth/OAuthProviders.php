<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support\OAuth;

use App\Modules\Identity\Data\SocialProvider;
use Illuminate\Contracts\Config\Repository;

/**
 * The providers this installation has credentials for. A provider without a client id and secret is not offered.
 */
final class OAuthProviders
{
    public function __construct(private readonly Repository $config) {}

    public function configured(SocialProvider $provider): bool
    {
        return $this->credentials($provider) !== null;
    }

    /**
     * @return list<SocialProvider>
     */
    public function available(): array
    {
        return array_values(array_filter(SocialProvider::cases(), fn (SocialProvider $provider): bool => $this->configured($provider)));
    }

    public function client(SocialProvider $provider): OAuthClient
    {
        [$id, $secret] = $this->credentials($provider) ?? throw new OAuthFailed("{$provider->label()} sign-in is not set up.");

        return match ($provider) {
            SocialProvider::Google => new GoogleClient($id, $secret),
            SocialProvider::GitHub => new GitHubClient($id, $secret),
        };
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function credentials(SocialProvider $provider): ?array
    {
        $id = $this->config->get("services.{$provider->value}.client_id");
        $secret = $this->config->get("services.{$provider->value}.client_secret");

        return is_string($id) && $id !== '' && is_string($secret) && $secret !== '' ? [$id, $secret] : null;
    }
}
