<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Identity\Queries\PersonLookup;
use App\Modules\Organizations\Data\InvitationSummary;
use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\Tenancy;
use Psr\Clock\ClockInterface;

/**
 * Describes an invitation to the person it was sent to and in the email about it. Looking one up by its link reveals
 * nothing about any other invitation: an unknown link is simply `unknown`.
 */
final class InvitationDetails
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly PersonLookup $people,
        private readonly ProjectLookup $projects,
        private readonly ClockInterface $clock,
    ) {}

    public function forToken(string $token): InvitationSummary
    {
        $invitation = Tenancy::crossTenant('an invitation link names its own organization', fn () => Invitation::query()
            ->whereIn('token_hash', $this->hasher->candidates($token))
            ->first());

        return $invitation === null ? new InvitationSummary('unknown') : $this->describe($invitation);
    }

    public function forId(string $organizationId, string $invitationId): ?InvitationSummary
    {
        $invitation = Tenancy::crossTenant('describe one invitation for its email', fn () => Invitation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($invitationId)
            ->first());

        return $invitation === null ? null : $this->describe($invitation);
    }

    private function describe(Invitation $invitation): InvitationSummary
    {
        $organization = Tenancy::crossTenant('describe an invitation', fn () => Organization::query()->find($invitation->organization_id));

        $projects = Tenancy::crossTenant('describe an invitation', function () use ($invitation): array {
            $grants = $invitation->grants()->get();
            $names = [];

            foreach ($grants as $grant) {
                $project = $this->projects->byId($invitation->organization_id, $grant->project_id);

                if ($project !== null) {
                    $names[] = ['name' => $project->name, 'role' => $grant->role->value];
                }
            }

            return $names;
        });

        return new InvitationSummary(
            $this->state($invitation),
            $invitation->id,
            $invitation->organization_id,
            $invitation->email,
            $organization?->name,
            $this->people->nameOf($invitation->invited_by),
            $invitation->org_role,
            $projects,
            $invitation->expires_at?->getTimestamp(),
        );
    }

    private function state(Invitation $invitation): string
    {
        return match (true) {
            $invitation->status === InvitationStatus::Accepted => 'used',
            $invitation->status === InvitationStatus::Revoked => 'revoked',
            $invitation->status === InvitationStatus::Expired,
            $invitation->status === InvitationStatus::Pending && ($invitation->expires_at === null || $invitation->expires_at <= $this->clock->now()) => 'expired',
            $invitation->status === InvitationStatus::Pending => 'open',
            default => 'unknown',
        };
    }
}
