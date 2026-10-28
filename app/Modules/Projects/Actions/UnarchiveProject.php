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
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes an archived project editable again. It needs no plan check: an archived project already counts toward the limit.
 */
final class UnarchiveProject
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
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

            if ($project->status !== ProjectStatus::Archived) {
                throw ValidationException::withMessages(['project' => 'This project is not archived.']);
            }

            $project->forceFill(['status' => ProjectStatus::Active, 'archived_at' => null, 'archived_by' => null])->save();

            $this->audit->record($actor, 'project.unarchived', new AuditTarget('project', $project->id, $project->name), ['key' => $project->key], projectId: $project->id);
        }));
    }
}
