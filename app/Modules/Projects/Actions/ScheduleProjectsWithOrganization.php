<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use DateTimeImmutable;

/**
 * Schedules every project of an organization that is being deleted, for the organization's due time. A project that was already
 * scheduled on its own keeps its earlier date and is purged then. Not an entry point: the organization's deletion calls it
 * inside its own transaction, which has already checked the permission.
 */
final class ScheduleProjectsWithOrganization
{
    /** @return int how many projects were scheduled */
    public function handle(string $organizationId, DateTimeImmutable $now, DateTimeImmutable $due, ?string $by): int
    {
        $count = 0;

        foreach (Project::query()->where('organization_id', $organizationId)->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::Archived->value])->lockForUpdate()->get() as $project) {
            $project->forceFill([
                'status_before_deletion' => $project->status,
                'status' => ProjectStatus::DeletionScheduled,
                'deletion_scheduled_at' => $now,
                'deletion_due_at' => $due,
                'deletion_scheduled_by' => $by,
                'deletion_scheduled_with_org' => true,
            ])->save();
            $count++;
        }

        return $count;
    }
}
