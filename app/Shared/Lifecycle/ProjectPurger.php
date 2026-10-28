<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

/**
 * Removes one module's data for a project that has been deleted. Each module registers its own purger; the project's row stays
 * as a tombstone for audit references. A purger runs inside the tenant context of the project's organization and must be safe
 * to run twice.
 */
interface ProjectPurger
{
    /**
     * A stable name, for logs.
     */
    public function name(): string;

    public function purge(string $organizationId, string $projectId): void;
}
