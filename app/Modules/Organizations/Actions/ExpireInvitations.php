<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Queries\InvitationLookup;
use App\Shared\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Marks pending invitations whose seven days are up as expired, so lists read right. Accepting checks the time itself, so
 * this changes how an invitation is listed, never whether it works.
 */
final class ExpireInvitations
{
    public function __construct(
        private readonly InvitationLookup $lookup,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(): int
    {
        $expired = 0;

        foreach ($this->lookup->organizationsWithLapsedInvitations() as $organizationId) {
            $expired += $this->tenant->run($organizationId, fn (): int => Invitation::query()
                ->where('status', InvitationStatus::Pending->value)
                ->where('expires_at', '<', $this->clock->now())
                ->update(['status' => InvitationStatus::Expired->value]));
        }

        return $expired;
    }
}
