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
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Withdraws an invitation that has not been used. Its link stops working at once.
 */
final class RevokeInvitation
{
    public function __construct(
        private readonly InvitationAccess $access,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
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

                if (! $invitation->status->isOpen()) {
                    throw new InvitationUnavailable('This invitation is no longer open.');
                }

                $invitation->forceFill([
                    'status' => InvitationStatus::Revoked,
                    'revoked_by' => $actor->id(),
                    'revoked_at' => $this->clock->now(),
                ])->save();

                $this->audit->record($actor, 'invitation.revoked', new AuditTarget('invitation', $invitation->id, $invitation->email));
            });
        });
    }
}
