<?php

declare(strict_types=1);

namespace App\Modules\Environments\Data;

/**
 * An environment to create: its name, key and label color (a color name from the theme).
 */
final readonly class EnvironmentDefinition
{
    public function __construct(
        public string $name,
        public string $key,
        public string $color,
    ) {}
}
