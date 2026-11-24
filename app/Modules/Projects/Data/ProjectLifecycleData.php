<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

final readonly class ProjectLifecycleData
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        /** The project key, typed to confirm scheduling a deletion. */
        public ?string $typedKey = null,
    ) {}
}
