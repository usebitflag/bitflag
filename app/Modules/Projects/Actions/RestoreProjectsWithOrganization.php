<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Plans\EnforcePlanLimit;
use App\Shared\Plans\LimitKey;
use App\Shared\Plans\PlanLimitReached;

/**
 * Brings back the projects the organization scheduled when the organization's deletion is cancelled, each to the state it was
 * in. Each one is checked against the project limit; one that would go over stays scheduled (now on its own, so it can be
 * restored later or left to be deleted). Projects scheduled on their own stay scheduled. Not an entry point: the organization
 * action calls it inside its own transaction.
 */
final class RestoreProjectsWithOrganization
{
    public function __construct(private readonly EnforcePlanLimit $limits) {}

    /** @return list<string> the IDs of the projects that stayed scheduled because of the plan limit */
    public function handle(string $organizationId): array
    {
        $blocked = [];

        foreach (Project::query()->where('organization_id', $organizationId)->where('status', ProjectStatus::DeletionScheduled->value)->where('deletion_scheduled_with_org', true)->lockForUpdate()->get() as $project) {
            try {
                $this->limits->handle($organizationId, LimitKey::Projects);
            } catch (PlanLimitReached) {
                $project->forceFill(['deletion_scheduled_with_org' => false])->save();
                $blocked[] = $project->id;

                continue;
            }

            $project->forceFill([
                'status' => $project->status_before_deletion ?? ProjectStatus::Active,
                'status_before_deletion' => null,
                'deletion_scheduled_at' => null,
                'deletion_due_at' => null,
                'deletion_scheduled_by' => null,
                'deletion_scheduled_with_org' => false,
            ])->save();
        }

        return $blocked;
    }
}
