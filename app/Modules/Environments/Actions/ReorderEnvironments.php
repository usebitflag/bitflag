<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Environments\Data\ReorderEnvironmentsData;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Plans\PlanRestrictionGate;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Saves a new order for a project's active environments. The list must hold exactly the project's active environments;
 * if someone added or archived one meanwhile, it is a version conflict and nothing changes.
 */
final class ReorderEnvironments
{
    public function __construct(
        private readonly PlanRestrictionGate $restrictions,
        private readonly RenumberEnvironments $renumber,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws VersionConflict
     */
    public function handle(Actor $actor, ReorderEnvironmentsData $data): void
    {
        if (! $actor->can(Capability::EnvironmentManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, fn () => DB::transaction(function () use ($actor, $data): void {
            $this->restrictions->assertConfigWritable($data->organizationId, $data->projectId);

            $current = $this->renumber->activeIds($data->projectId);

            $given = $data->orderedIds;
            $sameSet = count($given) === count($current) && array_diff($given, $current) === [] && count(array_unique($given)) === count($given);

            if (! $sameSet) {
                throw new VersionConflict(count($given), count($current));
            }

            if ($given === $current) {
                return;
            }

            $this->renumber->handle($data->projectId, $given);

            $this->audit->record(
                $actor,
                'environment.reordered',
                new AuditTarget('project', $data->projectId, null),
                ['before' => $current, 'after' => $given],
                projectId: $data->projectId,
            );
        }));
    }
}
