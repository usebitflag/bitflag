<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\OrganizationDeletionData;
use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Actions\ScheduleProjectsWithOrganization;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Plans\BillingStatus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Schedules an organization for deletion after the grace period (7 days by default), and every project in it with it. It
 * stays readable, SDK keys keep working, and the Org Owner can cancel until the due time. Refused while a paid subscription is
 * active. The person types the organization's URL name to confirm.
 */
final class ScheduleOrganizationDeletion
{
    public function __construct(
        private readonly BillingStatus $billing,
        private readonly ScheduleProjectsWithOrganization $projects,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, OrganizationDeletionData $data): void
    {
        if (! $actor->can(Capability::OrgManage, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, fn () => DB::transaction(function () use ($actor, $data): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($data->organizationId);

            if ($data->typedSlug !== $organization->slug) {
                throw ValidationException::withMessages(['confirm' => 'Type the organization URL name to confirm.']);
            }

            if ($organization->status !== OrganizationStatus::Active) {
                throw ValidationException::withMessages(['organization' => 'This organization cannot be deleted right now.']);
            }

            if ($this->billing->hasActivePaidSubscription($organization->id)) {
                throw ValidationException::withMessages(['organization' => 'Cancel the paid subscription before deleting the organization.']);
            }

            $now = $this->clock->now();
            $due = $now->modify('+'.Config::integer('bitflag.lifecycle.organization_deletion_grace_days').' days');

            $organization->forceFill([
                'status' => OrganizationStatus::DeletionScheduled,
                'deletion_scheduled_at' => $now,
                'deletion_due_at' => $due,
                'deletion_scheduled_by' => $actor->id(),
            ])->save();

            $scheduled = $this->projects->handle($organization->id, $now, $due, $actor->id());

            $this->audit->record($actor, 'organization.deletion_scheduled', new AuditTarget('organization', $organization->id, $organization->name), ['due_at' => $due->format(DATE_ATOM), 'projects' => $scheduled]);
            $this->outbox->push(OutboxMessage::forOrganization($organization->id, 'internal.email.requested', 'organization', $organization->id, ['template' => 'organization_deletion_scheduled', 'organization_id' => $organization->id]));
        }));
    }
}
