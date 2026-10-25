<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * A short, readable name for a browser and operating system, such as "Chrome on macOS", taken from a User-Agent header.
 * It is a hint for a person scanning their sessions, never a security signal.
 */
final class DeviceLabel
{
    public const UNKNOWN = 'Unknown device';

    private const MAX_LENGTH = 120;

    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return self::UNKNOWN;
        }

        $browser = self::browser($userAgent);
        $system = self::system($userAgent);

        $label = match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => $system,
            default => self::UNKNOWN,
        };

        return mb_substr($label, 0, self::MAX_LENGTH);
    }

    /**
     * Order matters: Edge, Opera and Samsung Internet also say Chrome, and Chrome also says Safari.
     */
    private static function browser(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/'), str_contains($userAgent, 'EdgA/'), str_contains($userAgent, 'EdgiOS/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    /**
     * Order matters: iPhones and iPads also say "like Mac OS X", and Android also says Linux.
     */
    private static function system(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'iPhone') => 'iOS',
            str_contains($userAgent, 'iPad') => 'iPadOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux'), str_contains($userAgent, 'X11') => 'Linux',
            default => null,
        };
    }
}
