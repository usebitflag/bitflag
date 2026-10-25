<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\Tenancy;
use Psr\Clock\ClockInterface;

/**
 * Finds invitations across organizations for the jobs and links that start without an organization in hand: a link
 * names its own, a person's queued invitations may span several, and the expiry job looks at all.
 */
final class InvitationLookup
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The invitation a link belongs to, as an id and its organization, or null.
     *
     * @return array{id: string, organizationId: string}|null
     */
    public function byToken(string $token): ?array
    {
        $found = Tenancy::crossTenant('an invitation link names its own organization', fn () => Invitation::query()
            ->whereIn('token_hash', $this->hasher->candidates($token))
            ->first(['id', 'organization_id']));

        return $found === null ? null : ['id' => $found->id, 'organizationId' => $found->organization_id];
    }

    /**
     * The invitations a person queued while their own email was unconfirmed.
     *
     * @return list<array{id: string, organizationId: string}>
     */
    public function queuedBy(string $inviterId): array
    {
        $queued = Tenancy::crossTenant('one person\'s queued invitations may span organizations', fn (): array => Invitation::query()
            ->where('invited_by', $inviterId)
            ->where('status', InvitationStatus::Queued->value)
            ->get(['id', 'organization_id'])
            ->map(static fn (Invitation $invitation): array => ['id' => $invitation->id, 'organizationId' => $invitation->organization_id])
            ->all());

        return array_values($queued);
    }

    /**
     * The organizations that have a pending invitation whose time is up.
     *
     * @return list<string>
     */
    public function organizationsWithLapsedInvitations(): array
    {
        $organizations = Tenancy::crossTenant('the expiry job looks at every organization', fn (): array => Invitation::query()
            ->where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '<', $this->clock->now())
            ->get(['organization_id'])
            ->map(static fn (Invitation $invitation): string => $invitation->organization_id)
            ->unique()
            ->all());

        return array_values($organizations);
    }
}
