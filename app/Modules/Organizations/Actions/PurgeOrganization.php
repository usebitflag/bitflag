<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Actions\PurgeProject;
use App\Modules\Projects\Queries\DueDeletions;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Deletes an organization whose grace period has ended: its projects are purged first, then the organization is marked
 * deleted and its memberships are removed. System-only: the scheduler calls it, one organization at a time.
 */
final class PurgeOrganization
{
    public function __construct(
        private readonly PurgeProject $projects,
        private readonly DueDeletions $scheduled,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /** @return bool whether the organization was purged */
    public function handle(string $organizationId): bool
    {
        return $this->tenant->run($organizationId, fn (): bool => DB::transaction(function () use ($organizationId): bool {
            $organization = Organization::query()->lockForUpdate()->find($organizationId);

            if ($organization === null || $organization->status !== OrganizationStatus::DeletionScheduled || $organization->deletion_due_at === null || $organization->deletion_due_at > $this->clock->now()) {
                return false;
            }

            foreach ($this->scheduled->scheduledIn($organizationId) as $projectId) {
                $this->projects->handle($organizationId, $projectId);
            }

            ProjectMembership::query()->delete();
            OrganizationMembership::query()->delete();

            $organization->forceFill(['status' => OrganizationStatus::Deleted, 'deleted_at' => $this->clock->now()])->save();

            $this->audit->record(Actor::system('organization deletion'), 'organization.deleted', new AuditTarget('organization', $organization->id, $organization->name));
            $this->outbox->push(OutboxMessage::forOrganization($organization->id, 'internal.organization.deleted', 'organization', $organization->id, ['organization_id' => $organization->id]));

            return true;
        }));
    }
}
