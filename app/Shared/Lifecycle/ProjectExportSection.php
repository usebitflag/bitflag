<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

/**
 * One module's part of a project export. Sections are registered by the modules that own the data and are written under their
 * own name. A section never includes a credential, a secret or a person's context data. It runs inside the tenant context of the
 * project's organization.
 */
interface ProjectExportSection
{
    /**
     * The key of the section in the export file, for example `environments`.
     */
    public function name(): string;

    /**
     * @return array<array-key, mixed>
     */
    public function export(string $organizationId, string $projectId): array;
}
