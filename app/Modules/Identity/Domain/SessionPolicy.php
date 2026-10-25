<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * How long a session may last. It ends after too long without activity (7 days, or less if the organization being used
 * asks for it) and after 30 days whatever the activity. The platform values are the ceiling: an organization can only
 * tighten the idle limit, to 1, 12 or 24 hours.
 */
final class SessionPolicy
{
    public const PLATFORM_IDLE_MINUTES = 7 * 24 * 60;

    public const ABSOLUTE_MINUTES = 30 * 24 * 60;

    /** The idle limits an organization may choose, in minutes. */
    public const ORGANIZATION_IDLE_OPTIONS = [60, 720, 1440];

    /**
     * The idle limit that applies: the strictest of the platform's and the organization's.
     */
    public static function idleMinutes(?int $organizationMinutes): int
    {
        return $organizationMinutes === null ? self::PLATFORM_IDLE_MINUTES : min(self::PLATFORM_IDLE_MINUTES, $organizationMinutes);
    }

    /**
     * Whether the session has run out, and why. Times are Unix seconds. Null means it may continue.
     *
     * @return array{reason: string, limit_minutes: int}|null
     */
    public static function expiry(int $startedAt, int $lastSeenAt, int $now, ?int $organizationIdleMinutes): ?array
    {
        if ($now - $startedAt > self::ABSOLUTE_MINUTES * 60) {
            return ['reason' => 'absolute', 'limit_minutes' => self::ABSOLUTE_MINUTES];
        }

        $idle = self::idleMinutes($organizationIdleMinutes);

        if ($now - $lastSeenAt > $idle * 60) {
            return ['reason' => 'idle', 'limit_minutes' => $idle];
        }

        return null;
    }

    /**
     * "1 hour", "12 hours", "7 days": the limit as a person reads it.
     */
    public static function describe(int $minutes): string
    {
        [$unit, $count] = match (true) {
            $minutes % (24 * 60) === 0 => ['day', intdiv($minutes, 24 * 60)],
            $minutes % 60 === 0 => ['hour', intdiv($minutes, 60)],
            default => ['minute', $minutes],
        };

        return $count.' '.$unit.($count === 1 ? '' : 's');
    }

    /**
     * What the session-ended message says.
     */
    public static function message(string $reason, int $limitMinutes): string
    {
        $limit = self::describe($limitMinutes);

        return $reason === 'absolute'
            ? "Your session ended after {$limit}."
            : "Your session ended after {$limit} of inactivity.";
    }
}
