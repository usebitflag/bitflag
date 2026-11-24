<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

use DateTimeZone;

/**
 * What the General tab accepts. Each method returns the message to show for a bad value, or null.
 */
final class ProjectSettingsRules
{
    /** Days without a check before a flag is called stale. */
    public const STALE_AFTER_DAYS = [14, 30, 60, 90];

    public const NAME = 'Enter a name of up to 80 characters.';

    public const DESCRIPTION = 'Use up to 280 characters.';

    public const TIME_ZONE = 'Choose a time zone from the list.';

    public const STALE_AFTER = 'Choose 14, 30, 60 or 90 days.';

    public const FLAG_KEY_FORMAT = 'Choose kebab-case, snake_case or any format.';

    public const KEY_LOCKED = 'The key is fixed once SDKs use it.';

    public static function name(string $name): ?string
    {
        return $name === '' || mb_strlen($name) > 80 ? self::NAME : null;
    }

    public static function description(?string $description): ?string
    {
        return mb_strlen((string) $description) > 280 ? self::DESCRIPTION : null;
    }

    public static function timeZone(string $timeZone): ?string
    {
        return in_array($timeZone, DateTimeZone::listIdentifiers(), true) ? null : self::TIME_ZONE;
    }

    public static function staleAfterDays(int $days): ?string
    {
        return in_array($days, self::STALE_AFTER_DAYS, true) ? null : self::STALE_AFTER;
    }
}
