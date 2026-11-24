<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

final readonly class ProjectSummary
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $key,
        public string $name,
        public bool $active,
        /** active, archived or deletion_scheduled; a deleted project is never found. */
        public string $status = 'active',
        /** Unix seconds of the purge for a project waiting to be deleted. */
        public ?int $deletionDueAt = null,
    ) {}
}
