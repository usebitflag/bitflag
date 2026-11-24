<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Data\ResolvedCredential;
use Throwable;

/**
 * The 60-second cache of credential summaries, keyed by the hex of the secret's hash. It holds no secret.
 *
 * A request that read MySQL before a change committed may fill the cache after it, so the two writers differ on purpose:
 * the resolver fills only a missing entry (SET NX), and every state change overwrites with a plain SET. A late fill
 * then fails against the overwritten entry, and an early one is overwritten. Nothing here deletes. When Redis is down the
 * caller reads MySQL.
 */
final class CredentialCache
{
    /** How long a filled entry lives. After a revocation the worst case is this long. */
    public const TTL_SECONDS = 60;

    /** An overwrite lives longer than a fill, so a stale fill never outlasts it. */
    public const OVERWRITE_TTL_SECONDS = 120;

    public const NEGATIVE_TTL_SECONDS = 30;

    public function get(string $hash): ?ResolvedCredential
    {
        try {
            $raw = RedisClient::get()->get($this->key($hash));

            if (! is_string($raw)) {
                return null;
            }

            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

            return is_array($data) ? ResolvedCredential::fromArray($data) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Fills a missing entry. Returns false when one is already there or Redis is unavailable. */
    public function fill(string $hash, ResolvedCredential $summary): bool
    {
        return $this->write($hash, $summary, self::TTL_SECONDS, nx: true);
    }

    /** Replaces whatever is cached, after a state change commits. */
    public function overwrite(string $hash, ResolvedCredential $summary): bool
    {
        return $this->write($hash, $summary, self::OVERWRITE_TTL_SECONDS, nx: false);
    }

    public function isKnownMissing(string $hash): bool
    {
        try {
            return (int) RedisClient::get()->exists($this->negativeKey($hash)) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function rememberMissing(string $hash): void
    {
        try {
            RedisClient::get()->set($this->negativeKey($hash), '1', ['nx', 'ex' => self::NEGATIVE_TTL_SECONDS]);
        } catch (Throwable) {
            // Without Redis every unknown secret costs a query, which is slower but correct.
        }
    }

    private function write(string $hash, ResolvedCredential $summary, int $ttl, bool $nx): bool
    {
        try {
            $encoded = json_encode($summary->toArray(), JSON_THROW_ON_ERROR);
            $options = $nx ? ['nx', 'ex' => $ttl] : ['ex' => $ttl];

            return (bool) RedisClient::get()->set($this->key($hash), $encoded, $options);
        } catch (Throwable) {
            return false;
        }
    }

    private function key(string $hash): string
    {
        return 'bf:cred:'.bin2hex($hash);
    }

    private function negativeKey(string $hash): string
    {
        return 'bf:cred:neg:'.bin2hex($hash);
    }
}
