<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support\OAuth;

use App\Modules\Identity\Data\SocialProvider;
use Illuminate\Contracts\Session\Session;
use Psr\Clock\ClockInterface;

/**
 * What the browser must carry through the provider and back: a random `state` that proves the return trip is ours, and the
 * PKCE verifier. Both live in the session, are good for ten minutes and work once.
 */
final class OAuthState
{
    private const KEY = 'oauth.pending';

    private const MINUTES = 10;

    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @return array{state: string, challenge: string}
     */
    public function begin(Session $session, SocialProvider $provider): array
    {
        $state = bin2hex(random_bytes(20));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        $session->put(self::KEY, [
            'state' => $state,
            'provider' => $provider->value,
            'verifier' => $verifier,
            'expires_at' => $this->clock->now()->modify('+'.self::MINUTES.' minutes')->getTimestamp(),
        ]);

        return ['state' => $state, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /**
     * The verifier for a return trip with this state, or null when the state is wrong, for another provider, used or old.
     * It is spent either way.
     */
    public function consume(Session $session, SocialProvider $provider, string $state): ?string
    {
        $pending = $session->pull(self::KEY);

        if (! is_array($pending)
            || ! is_string($pending['state'] ?? null)
            || ! is_string($pending['verifier'] ?? null)
            || ($pending['provider'] ?? null) !== $provider->value
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < $this->clock->now()->getTimestamp()
            || ! hash_equals($pending['state'], $state)) {
            return null;
        }

        return $pending['verifier'];
    }
}
