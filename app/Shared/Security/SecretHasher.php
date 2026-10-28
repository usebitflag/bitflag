<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Hashes high-entropy secrets (recovery codes, invitation tokens, trusted-browser tokens and, from the credentials
 * work, API keys) with HMAC-SHA256 under a pepper that is kept outside the database. A database dump alone then cannot
 * even test guesses.
 *
 * This is deliberately not bcrypt or argon2: these secrets carry enough entropy that guessing is infeasible, and a slow
 * hash would make every lookup expensive and rule out an indexed query. Passwords still use the framework hasher.
 */
final class SecretHasher
{
    private const PEPPER_BYTES = 32;

    /**
     * @param  array<int, string|null>  $peppers  Base64-encoded 32-byte keys by pepper id.
     */
    public function __construct(
        private readonly array $peppers,
        private readonly int $currentPepperId,
        private readonly ?int $previousPepperId = null,
    ) {}

    public function currentPepperId(): int
    {
        return $this->currentPepperId;
    }

    /**
     * The 32 raw bytes to store, under the current pepper unless another is named.
     */
    public function hash(string $secret, ?int $pepperId = null): string
    {
        return hash_hmac('sha256', $secret, $this->pepper($pepperId ?? $this->currentPepperId), true);
    }

    /**
     * The hashes a presented secret may have been stored under, newest pepper first. Look the secret up by these, so a
     * pepper can be rotated without invalidating what is already stored.
     *
     * @return list<string>
     */
    public function candidates(string $secret): array
    {
        $hashes = [$this->hash($secret, $this->currentPepperId)];

        if ($this->previousPepperId !== null && $this->previousPepperId !== $this->currentPepperId) {
            $hashes[] = $this->hash($secret, $this->previousPepperId);
        }

        return $hashes;
    }

    public function matches(string $secret, string $storedHash): bool
    {
        foreach ($this->candidates($secret) as $candidate) {
            if (hash_equals($storedHash, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function pepper(int $pepperId): string
    {
        $encoded = $this->peppers[$pepperId] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            throw PepperNotConfigured::missing($pepperId);
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== self::PEPPER_BYTES) {
            throw PepperNotConfigured::malformed($pepperId);
        }

        return $raw;
    }
}
