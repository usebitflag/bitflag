<?php

declare(strict_types=1);

namespace App\Modules\Environments\Data;

final readonly class ArchiveEnvironmentData
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        /** The environment's key, typed to confirm. */
        public string $typedKey,
    ) {}
}
