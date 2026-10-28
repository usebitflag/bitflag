<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\OrganizationDeletionData;
use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Actions\RestoreProjectsWithOrganization;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Cancels an organization's scheduled deletion until its due time. The projects the organization scheduled come back to the
 * state they were in, each checked against the project limit; a project that would go over the limit, and one that was
 * scheduled on its own, stay scheduled.
 */
final class CancelOrganizationDeletion
{
    public function __construct(
        private readonly RestoreProjectsWithOrganization $projects,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return list<string> the IDs of projects that stayed scheduled because of the plan limit
     *
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, OrganizationDeletionData $data): array
    {
        if (! $actor->can(Capability::OrgManage, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        return $this->tenant->run($data->organizationId, fn (): array => DB::transaction(function () use ($actor, $data): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($data->organizationId);

            if ($organization->status !== OrganizationStatus::DeletionScheduled || $organization->deletion_due_at === null || $organization->deletion_due_at <= $this->clock->now()) {
                throw ValidationException::withMessages(['organization' => 'This organization can no longer be restored.']);
            }

            $organization->forceFill([
                'status' => OrganizationStatus::Active,
                'deletion_scheduled_at' => null,
                'deletion_due_at' => null,
                'deletion_scheduled_by' => null,
            ])->save();

            $blocked = $this->projects->handle($organization->id);

            $this->audit->record($actor, 'organization.deletion_cancelled', new AuditTarget('organization', $organization->id, $organization->name), ['projects_left_scheduled' => count($blocked)]);

            return $blocked;
        }));
    }
}
