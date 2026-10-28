<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Queries\InvitationLookup;
use App\Shared\Outbox\Outbox;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Sends the invitations a person queued before they confirmed their email. Runs when they confirm it.
 */
final class ReleaseQueuedInvitations
{
    public function __construct(
        private readonly InvitationLookup $lookup,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return int how many invitations were released
     */
    public function handle(string $inviterId): int
    {
        $queued = $this->lookup->queuedBy($inviterId);

        foreach ($queued as $row) {
            $this->tenant->run($row['organizationId'], function () use ($row): void {
                DB::transaction(function () use ($row): void {
                    $invitation = Invitation::query()->whereKey($row['id'])->lockForUpdate()->first();

                    // Withdrawn while it waited: leave it alone.
                    if ($invitation === null || $invitation->status !== InvitationStatus::Queued) {
                        return;
                    }

                    $invitation->forceFill(['status' => InvitationStatus::Pending])->save();
                    $this->outbox->push(InviteMembers::emailRequest($invitation->organization_id, $invitation->id));
                });
            });
        }

        return count($queued);
    }
}
