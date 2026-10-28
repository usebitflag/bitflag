<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Environments\Data\ArchiveEnvironmentData;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Environments\Queries\EnvironmentDeleteBlockers;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Plans\PlanRestrictionGate;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes an environment that was never published and never had a key of any state, together with its policy versions. Anything
 * else can only be archived. The project's last environment cannot be deleted. The person types the key to confirm.
 */
final class DeleteEnvironment
{
    public function __construct(
        private readonly PlanRestrictionGate $restrictions,
        private readonly RenumberEnvironments $renumber,
        private readonly EnvironmentDeleteBlockers $blockers,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
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
            $environment = Environment::query()->where('project_id', $data->projectId)->lockForUpdate()->find($data->environmentId);

            if ($environment === null) {
                throw new ResourceNotFound;
            }

            if ($data->typedKey !== $environment->key) {
                throw ValidationException::withMessages(['confirm' => 'Type the environment key to confirm.']);
            }

            if ($this->blockers->for($environment->id) !== []) {
                throw ValidationException::withMessages(['environment' => 'This environment has history. Archive it instead.']);
            }

            if ($environment->status === EnvironmentStatus::Active && count($activeIds) < 2) {
                throw ValidationException::withMessages(['environment' => 'A project needs at least one active environment.']);
            }

            $name = $environment->name;
            $key = $environment->key;

            // The policy rows are never edited, but they go with the environment they describe.
            Environment::query()->whereKey($environment->id)->update(['current_policy_id' => null]);
            EnvironmentPolicy::query()->where('environment_id', $environment->id)->delete();
            Environment::query()->whereKey($environment->id)->delete();

            $this->renumber->handle($data->projectId, array_values(array_filter($activeIds, static fn (string $id): bool => $id !== $environment->id)));

            $this->audit->record(
                $actor,
                'environment.deleted',
                new AuditTarget('environment', $environment->id, $name),
                ['key' => $key],
                projectId: $data->projectId,
                environmentId: $environment->id,
            );
        }));
    }
}
