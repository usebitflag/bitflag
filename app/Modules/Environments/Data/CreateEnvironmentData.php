<?php

declare(strict_types=1);

namespace App\Modules\Environments\Data;

final readonly class CreateEnvironmentData
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $name,
        public string $key,
        public string $color,
        /** The environment the new one goes after, or null for the end of the list. */
        public ?string $afterEnvironmentId,
        public bool $protected,
    ) {}
}
