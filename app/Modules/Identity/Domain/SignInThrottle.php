<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use Illuminate\Contracts\Cache\Repository;
use Psr\Clock\ClockInterface;

/**
 * Counts failed sign-ins per email address and locks the address after too many. The counter is keyed on a hash of the
 * lowercased address and does not look at whether an account exists, so an unknown address locks, and answers, exactly
 * like a real one.
 */
final class SignInThrottle
{
    public const MAX_FAILURES = 5;

    public const WINDOW_SECONDS = 900;

    public const LOCK_SECONDS = 900;

    public const LOCKED_MESSAGE = 'Too many attempts. Try again in 15 minutes.';

    public function __construct(
        private readonly Repository $cache,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Whether the address is locked now. The lock is a moment on the application clock, not a cache expiry, so it follows
     * the clock the rest of the application follows.
     */
    public function isLocked(string $email): bool
    {
        $until = $this->cache->get($this->key('lock', $email));

        return is_int($until) && $until > $this->clock->now()->getTimestamp();
    }

    /**
     * Counts a failure and returns how many there have been in the current window. Reaching the limit locks the address.
     */
    public function recordFailure(string $email): int
    {
        $key = $this->key('failures', $email);

        $this->cache->add($key, 0, self::WINDOW_SECONDS);
        $failures = (int) $this->cache->increment($key);

        if ($failures >= self::MAX_FAILURES) {
            // Kept a little longer than the lock itself, so the entry is gone soon after it stops mattering.
            $this->cache->put($this->key('lock', $email), $this->clock->now()->getTimestamp() + self::LOCK_SECONDS, self::LOCK_SECONDS * 2);
        }

        return $failures;
    }

    public function clear(string $email): void
    {
        $this->cache->forget($this->key('failures', $email));
        $this->cache->forget($this->key('lock', $email));
    }

    private function key(string $kind, string $email): string
    {
        return "signin:{$kind}:".hash('sha256', mb_strtolower(trim($email)));
    }
}
