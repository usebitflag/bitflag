<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\ExportProjectData;
use App\Modules\Projects\Domain\ExportStatus;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Jobs\RunProjectExport;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExport;
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
 * Asks for a JSON export of a project and queues it. The file holds the project's settings and what the modules register,
 * never a key or a token, and is kept for 24 hours. One export per project at a time.
 */
final class ExportProject
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return string the export's ID
     *
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, ExportProjectData $data): string
    {
        if (! $actor->can(Capability::ProjectManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        return $this->tenant->run($data->organizationId, fn (): string => DB::transaction(function () use ($actor, $data): string {
            $project = Project::query()->lockForUpdate()->findOrFail($data->projectId);

            if ($project->status === ProjectStatus::Deleted) {
                throw new AuthorizationDenied;
            }

            $busy = ProjectExport::query()->where('project_id', $project->id)->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])->exists();

            if ($busy) {
                throw ValidationException::withMessages(['export' => 'An export is already being prepared. Wait for it to finish.']);
            }

            $export = ProjectExport::query()->create([
                'organization_id' => $data->organizationId,
                'project_id' => $project->id,
                'requested_by' => $actor->id(),
                'status' => ExportStatus::Queued,
            ]);

            $this->audit->record($actor, 'project.exported', new AuditTarget('project', $project->id, $project->name), ['export_id' => $export->id], projectId: $project->id);

            DB::afterCommit(static fn () => RunProjectExport::dispatch($data->organizationId, $export->id));

            return $export->id;
        }));
    }
}
