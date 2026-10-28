<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Queries\EnvironmentCredentials;
use App\Modules\Environments\Data\ArchiveEnvironmentData;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Models\Environment;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Plans\PlanRestrictionGate;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Archives an environment: its keys are revoked at once (SDKs get 401), nothing more is published to it, it disappears from
 * pickers and flag pages, its history stays readable, and it stops counting toward the plan. It cannot be undone, and the
 * project's last active environment cannot be archived. The person types the key to confirm.
 */
final class ArchiveEnvironment
{
    public function __construct(
        private readonly PlanRestrictionGate $restrictions,
        private readonly RenumberEnvironments $renumber,
        private readonly EnvironmentCredentials $credentials,
        private readonly RevokeCredential $revoke,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ResourceNotFound
     * @throws ValidationException
     */
    public function handle(Actor $actor, ArchiveEnvironmentData $data): void
    {
        if (! $actor->can(Capability::EnvironmentManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, fn () => DB::transaction(function () use ($actor, $data): void {
            $this->restrictions->assertConfigWritable($data->organizationId, $data->projectId, $data->environmentId);

            $activeIds = $this->renumber->activeIds($data->projectId);
            $environment = Environment::query()->where('project_id', $data->projectId)->find($data->environmentId);

            if ($environment === null) {
                throw new ResourceNotFound;
            }

            if ($data->typedKey !== $environment->key) {
                throw ValidationException::withMessages(['confirm' => 'Type the environment key to confirm.']);
            }

            if (! in_array($environment->id, $activeIds, true)) {
                throw ValidationException::withMessages(['environment' => 'This environment is already archived.']);
            }

            if (count($activeIds) < 2) {
                throw ValidationException::withMessages(['environment' => 'A project needs at least one active environment.']);
            }

            $environment->forceFill([
                'status' => EnvironmentStatus::Archived,
                'archived_at' => $this->clock->now(),
                'archived_by' => $actor->id(),
            ])->save();

            $revoked = $this->credentials->workingIds($environment->id);

            foreach ($revoked as $credentialId) {
                $this->revoke->handle(Actor::system('environment archive'), RevokeCredentialData::environmentArchived($data->organizationId, $credentialId));
            }

            $this->renumber->handle($data->projectId, array_values(array_filter($activeIds, static fn (string $id): bool => $id !== $environment->id)));

            $this->audit->record(
                $actor,
                'environment.archived',
                new AuditTarget('environment', $environment->id, $environment->name),
                ['key' => $environment->key, 'revoked_keys' => count($revoked)],
                projectId: $data->projectId,
                environmentId: $environment->id,
            );
            $this->outbox->push(OutboxMessage::forOrganization($data->organizationId, 'internal.environment.archived', 'environment', $environment->id, ['environment_id' => $environment->id], projectId: $data->projectId, environmentId: $environment->id));
        }));
    }
}
