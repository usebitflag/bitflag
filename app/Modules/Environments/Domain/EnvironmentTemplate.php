<?php

declare(strict_types=1);

namespace App\Modules\Environments\Domain;

/**
 * The environments a new project starts with, and how protection defaults. An environment named production (or prod) starts
 * protected: publishing needs a reason and is limited to Owners and Publishers. The others start open.
 */
final class EnvironmentTemplate
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/';

    public const MAX_ENVIRONMENTS = 10;

    /**
     * @return list<array{name: string, key: string, color: EnvironmentColor}>
     */
    public static function default(): array
    {
        return [
            ['name' => 'Development', 'key' => 'development', 'color' => EnvironmentColor::Slate],
            ['name' => 'Staging', 'key' => 'staging', 'color' => EnvironmentColor::Lilac],
            ['name' => 'Production', 'key' => 'production', 'color' => EnvironmentColor::Violet],
        ];
    }

    public static function startsProtected(string $key): bool
    {
        return in_array($key, ['production', 'prod'], true);
    }

    /**
     * The color a template environment has, or a neutral one for any other.
     */
    public static function colorFor(string $key): EnvironmentColor
    {
        return match ($key) {
            'development' => EnvironmentColor::Slate,
            'staging' => EnvironmentColor::Lilac,
            'production', 'prod' => EnvironmentColor::Violet,
            default => EnvironmentColor::Ink,
        };
    }
}
