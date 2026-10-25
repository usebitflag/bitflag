<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Domain\InvitationRules;
use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Makes the link for a pending invitation when its email is sent: a new random token, of which only the peppered hash is
 * kept, good for seven days from now. Any earlier link stops working. Returns the token, or null if the invitation is no
 * longer pending (withdrawn or used while the email waited its turn).
 */
final class IssueInvitationLink
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(string $organizationId, string $invitationId): ?string
    {
        return $this->tenant->run($organizationId, function () use ($invitationId): ?string {
            $invitation = Invitation::query()->whereKey($invitationId)->first();

            if ($invitation === null || $invitation->status !== InvitationStatus::Pending) {
                return null;
            }

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $now = $this->clock->now();

            $invitation->forceFill([
                'token_hash' => $this->hasher->hash($token),
                'sent_at' => $now,
                'expires_at' => $now->modify('+'.InvitationRules::VALID_DAYS.' days'),
            ])->save();

            return $token;
        });
    }
}
