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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Archives an active project: it becomes read-only, keeps serving its last published snapshots and still counts toward the
 * plan. It can be unarchived. Rotating and revoking keys stay available.
 */
final class ArchiveProject
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

            if ($project->status !== ProjectStatus::Active) {
                throw ValidationException::withMessages(['project' => 'Only an active project can be archived.']);
            }

            $project->forceFill(['status' => ProjectStatus::Archived, 'archived_at' => $this->clock->now(), 'archived_by' => $actor->id()])->save();

            $this->audit->record($actor, 'project.archived', new AuditTarget('project', $project->id, $project->name), ['key' => $project->key], projectId: $project->id);
            $this->outbox->push(OutboxMessage::forOrganization($data->organizationId, 'internal.project.archived', 'project', $project->id, ['project_id' => $project->id], projectId: $project->id));
        }));
    }
}
