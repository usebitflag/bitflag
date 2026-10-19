<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

/**
 * The sidebar groups, in display order.
 */
enum NavigationGroup: string
{
    case Project = 'project';
    case Configuration = 'configuration';
    case Releases = 'releases';
    case Insights = 'insights';
    case Administration = 'administration';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Configuration => 'Configuration',
            self::Releases => 'Releases',
            self::Insights => 'Insights',
            self::Administration => 'Administration',
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::Project => 0,
            self::Configuration => 1,
            self::Releases => 2,
            self::Insights => 3,
            self::Administration => 4,
        };
    }
}
