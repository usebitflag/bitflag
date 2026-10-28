<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Projects\Domain\Exceptions\ProjectNotWritable;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;

/**
 * Whether a project's configuration can change. Only an active project can be edited; an archived one and one waiting to be
 * deleted are read-only (and keep serving). Actions that change settings, environments or add credentials call it first,
 * inside the tenant context of the organization.
 */
final class ProjectWritability
{
    /**
     * @throws ProjectNotWritable
     */
    public function assertWritable(string $projectId): void
    {
        $status = Project::query()->find($projectId)?->status;

        if ($status === ProjectStatus::Archived || $status === ProjectStatus::DeletionScheduled) {
            throw new ProjectNotWritable($status->value);
        }
    }
}
