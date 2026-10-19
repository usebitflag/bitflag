<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

/**
 * How long a failed receipt waits before the next attempt, and when it gives up.
 */
final class RetrySchedule
{
    public const MAX_ATTEMPTS = 12;

    /** Seconds before attempts 2 to 6. Every later attempt waits an hour. */
    private const DELAYS = [10, 30, 120, 600, 1800];

    private const LATER_DELAY = 3600;

    /**
     * Seconds to wait after the given failed attempt (1 for the first failure).
     */
    public static function delayAfter(int $failedAttempt): int
    {
        return self::DELAYS[$failedAttempt - 1] ?? self::LATER_DELAY;
    }

    public static function isExhausted(int $attempts): bool
    {
        return $attempts >= self::MAX_ATTEMPTS;
    }
}
