<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Queries\EnvironmentCredentials;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Lifecycle\ProjectPurgers;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Deletes a project whose grace period has ended. Every key and token is revoked (SDKs get 401), each module's purger removes
 * its data for the project, the project is marked deleted and its key is freed. The row stays as a tombstone that audit entries
 * point at. System-only: the scheduler calls it, one project at a time. Calling it twice does nothing the second time.
 */
final class PurgeProject
{
    public function __construct(
        private readonly EnvironmentCredentials $credentials,
        private readonly RevokeCredential $revoke,
        private readonly ProjectPurgers $purgers,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /** @return bool whether the project was purged */
    public function handle(string $organizationId, string $projectId): bool
    {
        return $this->tenant->run($organizationId, fn (): bool => DB::transaction(function () use ($organizationId, $projectId): bool {
            $project = Project::query()->lockForUpdate()->find($projectId);

            if ($project === null || $project->status !== ProjectStatus::DeletionScheduled || $project->deletion_due_at === null || $project->deletion_due_at > $this->clock->now()) {
                return false;
            }

            foreach ($this->credentials->workingIdsInProject($project->id) as $credentialId) {
                $this->revoke->handle(Actor::system('project deletion'), RevokeCredentialData::projectDeleted($organizationId, $credentialId));
            }

            foreach ($this->purgers->all() as $purger) {
                $purger->purge($organizationId, $project->id);
            }

            $name = $project->name;
            $key = $project->key;

            $project->forceFill([
                'status' => ProjectStatus::Deleted,
                'key' => 'deleted-'.strtolower($project->id),
                'deleted_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(Actor::system('project deletion'), 'project.deleted', new AuditTarget('project', $project->id, $name), ['key' => $key], projectId: $project->id);
            $this->outbox->push(OutboxMessage::forOrganization($organizationId, 'internal.project.deleted', 'project', $project->id, ['project_id' => $project->id], projectId: $project->id));

            return true;
        }));
    }
}
