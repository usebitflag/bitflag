<?php

declare(strict_types=1);

namespace App\Modules\Environments\Data;

final readonly class EnvironmentSummary
{
    /**
     * @param  int|null  $revision  The live revision; null until the environment has been published.
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $projectId,
        public string $key,
        public string $name,
        public string $color,
        public int $position,
        public bool $active,
        public ?int $revision = null,
    ) {}
}
