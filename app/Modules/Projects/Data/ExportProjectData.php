<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

final readonly class ExportProjectData
{
    public function __construct(public string $organizationId, public string $projectId) {}
}
