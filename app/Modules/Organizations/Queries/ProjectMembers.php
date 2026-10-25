<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Identity\Queries\PersonLookup;
use App\Modules\Identity\Queries\TwoFactorStatus;
use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\InvitationProjectGrant;
use App\Modules\Organizations\Models\ProjectMembership;
use Psr\Clock\ClockInterface;

/**
 * Who belongs to a project, for its Members tab: each person with their role there (and the version to edit it from),
 * two-factor and last activity, the count of people per role, and the invitations that would add someone to it. Runs inside
 * the organization's tenant context.
 */
final class ProjectMembers
{
    public function __construct(
        private readonly PersonLookup $people,
        private readonly TwoFactorStatus $twoFactor,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return list<array{membershipId: string, userId: string, name: string, email: string, role: string, version: int, twoFactor: bool, lastActiveAt: int|null}>
     */
    public function members(string $projectId): array
    {
        $memberships = ProjectMembership::query()->where('project_id', $projectId)->get();
        $ids = array_values($memberships->map(static fn (ProjectMembership $membership): string => $membership->user_id)->all());
        $profiles = $this->people->profiles($ids);
        $enrolled = array_flip($this->twoFactor->enrolledAmong($ids));
        $rows = [];

        foreach ($memberships as $membership) {
            $profile = $profiles[$membership->user_id] ?? null;

            if ($profile === null) {
                continue;
            }

            $rows[] = [
                'membershipId' => $membership->id,
                'userId' => $membership->user_id,
                'name' => $profile['name'],
                'email' => $profile['email'],
                'role' => $membership->role->value,
                'version' => $membership->version,
                'twoFactor' => isset($enrolled[$membership->user_id]),
                'lastActiveAt' => $profile['lastActiveAt'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @return array{owner: int, publisher: int, developer: int, viewer: int}
     */
    public function roleCounts(string $projectId): array
    {
        $counts = ['owner' => 0, 'publisher' => 0, 'developer' => 0, 'viewer' => 0];

        foreach (ProjectMembership::query()->where('project_id', $projectId)->get() as $membership) {
            $counts[$membership->role->value]++;
        }

        return $counts;
    }

    /**
     * The open invitations that include this project.
     *
     * @return list<array{id: string, email: string, role: string, status: string, inviter: string, expiresAt: int|null}>
     */
    public function openInvitations(string $projectId): array
    {
        $grants = InvitationProjectGrant::query()->where('project_id', $projectId)->get()->keyBy('invitation_id');

        if ($grants->isEmpty()) {
            return [];
        }

        $invitations = Invitation::query()
            ->whereIn('id', $grants->keys()->all())
            ->whereIn('status', [InvitationStatus::Queued->value, InvitationStatus::Pending->value])
            ->orderByDesc('id')
            ->get();
        $inviters = $this->people->profiles(array_values(array_unique($invitations->map(static fn (Invitation $invitation): string => $invitation->invited_by)->all())));
        $now = $this->clock->now();
        $rows = [];

        foreach ($invitations as $invitation) {
            $grant = $grants->get($invitation->id);

            $rows[] = [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $grant instanceof InvitationProjectGrant ? $grant->role->value : 'viewer',
                'status' => $invitation->status === InvitationStatus::Pending && $invitation->expires_at !== null && $invitation->expires_at <= $now ? 'expired' : $invitation->status->value,
                'inviter' => $inviters[$invitation->invited_by]['name'] ?? 'Someone',
                'expiresAt' => $invitation->expires_at?->getTimestamp(),
            ];
        }

        return $rows;
    }
}
