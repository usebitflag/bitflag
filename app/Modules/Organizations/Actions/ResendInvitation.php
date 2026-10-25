<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\ChangeInvitationData;
use App\Modules\Organizations\Domain\Exceptions\InvitationUnavailable;
use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Support\InvitationAccess;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Sends a pending invitation again. The old link stops working and a new one, good for seven days, goes out. Only a
 * pending invitation can be sent again: one that has run out, been used or been withdrawn is replaced by a new invitation.
 */
final class ResendInvitation
{
    public function __construct(
        private readonly InvitationAccess $access,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws InvitationUnavailable
     */
    public function handle(Actor $actor, ChangeInvitationData $data): void
    {
        $this->tenant->run($data->organizationId, function () use ($actor, $data): void {
            DB::transaction(function () use ($actor, $data): void {
                $invitation = Invitation::query()->whereKey($data->invitationId)->lockForUpdate()->firstOrFail();
                $this->access->assertCanManage($actor, $invitation);

                if ($invitation->status !== InvitationStatus::Pending) {
                    throw new InvitationUnavailable('Only a pending invitation can be sent again.');
                }

                $this->audit->record($actor, 'invitation.resent', new AuditTarget('invitation', $invitation->id, $invitation->email));
                $this->outbox->push(InviteMembers::emailRequest($invitation->organization_id, $invitation->id));
            });
        });
    }
}
