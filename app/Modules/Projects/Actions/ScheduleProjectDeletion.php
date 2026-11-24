<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\ProjectLifecycleData;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Schedules a project for deletion after the grace period (7 days by default). Until then it is read-only, SDK keys keep
 * working, and it can be restored. The person types the project key to confirm. Organization Owners and project Owners are
 * told by email.
 */
final class ScheduleProjectDeletion
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, ProjectLifecycleData $data): void
    {
        if (! $actor->can(Capability::ProjectManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, fn () => DB::transaction(function () use ($actor, $data): void {
            $project = Project::query()->lockForUpdate()->findOrFail($data->projectId);

            if ($data->typedKey !== $project->key) {
                throw ValidationException::withMessages(['confirm' => 'Type the project key to confirm.']);
            }

            if (! in_array($project->status, [ProjectStatus::Active, ProjectStatus::Archived], true)) {
                throw ValidationException::withMessages(['project' => 'This project is already scheduled for deletion.']);
            }

            $now = $this->clock->now();
            $due = $now->modify('+'.Config::integer('bitflag.lifecycle.project_deletion_grace_days').' days');

            $project->forceFill([
                'status_before_deletion' => $project->status,
                'status' => ProjectStatus::DeletionScheduled,
                'deletion_scheduled_at' => $now,
                'deletion_due_at' => $due,
                'deletion_scheduled_by' => $actor->id(),
                'deletion_scheduled_with_org' => false,
            ])->save();

            $this->audit->record($actor, 'project.deletion_scheduled', new AuditTarget('project', $project->id, $project->name), ['key' => $project->key, 'due_at' => $due->format(DATE_ATOM)], projectId: $project->id);
            $this->outbox->push(OutboxMessage::forOrganization($data->organizationId, 'internal.email.requested', 'project', $project->id, ['template' => 'project_deletion_scheduled', 'project_id' => $project->id], projectId: $project->id));
        }));
    }
}
