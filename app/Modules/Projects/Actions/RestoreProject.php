<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Organizations\Queries\OrganizationStatuses;
use App\Modules\Projects\Data\ProjectLifecycleData;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Plans\EnforcePlanLimit;
use App\Shared\Plans\LimitKey;
use App\Shared\Plans\PlanLimitReached;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Cancels a scheduled deletion, until the project's due time, and returns it to the state it was in. Restoring checks the
 * project limit, because a project waiting to be deleted does not count and a restored one does. A project the organization
 * scheduled comes back only with the organization.
 */
final class RestoreProject
{
    public function __construct(
        private readonly EnforcePlanLimit $limits,
        private readonly OrganizationStatuses $organizations,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws PlanLimitReached
     * @throws ValidationException
     */
    public function handle(Actor $actor, ProjectLifecycleData $data): void
    {
        if (! $actor->can(Capability::ProjectManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, fn () => DB::transaction(function () use ($actor, $data): void {
            $project = Project::query()->lockForUpdate()->findOrFail($data->projectId);

            if ($project->status !== ProjectStatus::DeletionScheduled || $project->deletion_due_at === null || $project->deletion_due_at <= $this->clock->now()) {
                throw ValidationException::withMessages(['project' => 'This project can no longer be restored.']);
            }

            if ($project->deletion_scheduled_with_org && ! $this->organizations->isActive($data->organizationId)) {
                throw ValidationException::withMessages(['project' => 'Restore the organization first.']);
            }

            $this->limits->handle($data->organizationId, LimitKey::Projects);

            $this->bringBack($project);

            $this->audit->record($actor, 'project.deletion_cancelled', new AuditTarget('project', $project->id, $project->name), ['key' => $project->key], projectId: $project->id);
        }));
    }

    private function bringBack(Project $project): void
    {
        $project->forceFill([
            'status' => $project->status_before_deletion ?? ProjectStatus::Active,
            'status_before_deletion' => null,
            'deletion_scheduled_at' => null,
            'deletion_due_at' => null,
            'deletion_scheduled_by' => null,
            'deletion_scheduled_with_org' => false,
        ])->save();
    }
}
