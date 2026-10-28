<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Identity\Queries\PersonLookup;
use App\Modules\Identity\Queries\TwoFactorStatus;
use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Queries\ProjectLookup;
use Psr\Clock\ClockInterface;

/**
 * Who belongs to an organization, for the Members page: each person with their organization role, the projects they can
 * open, whether they use two-factor and when they were last active, and the invitations still open. Runs inside the
 * organization's tenant context.
 */
final class OrganizationMembers
{
    public function __construct(
        private readonly PersonLookup $people,
        private readonly TwoFactorStatus $twoFactor,
        private readonly ProjectLookup $projects,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return list<array{membershipId: string, userId: string, name: string, email: string, orgRole: string, twoFactor: bool, lastActiveAt: int|null, projects: list<array{name: string, role: string}>}>
     */
    public function members(string $organizationId, ?string $onlyUserId = null): array
    {
        $memberships = OrganizationMembership::query()
            ->when($onlyUserId !== null, fn ($query) => $query->where('user_id', $onlyUserId))
            ->get();
        $ids = $memberships->map(static fn (OrganizationMembership $membership): string => $membership->user_id)->all();
        $profiles = $this->people->profiles(array_values($ids));
        $enrolled = array_flip($this->twoFactor->enrolledAmong(array_values($ids)));
        $projectNames = $this->projectNames($organizationId);
        $access = [];

        foreach (ProjectMembership::query()->whereIn('user_id', $ids)->get() as $project) {
            if (isset($projectNames[$project->project_id])) {
                $access[$project->user_id][] = ['name' => $projectNames[$project->project_id], 'role' => $project->role->value];
            }
        }

        $rows = [];

        foreach ($memberships as $membership) {
            $profile = $profiles[$membership->user_id] ?? null;

            if ($profile === null) {
                continue;
            }

            $projects = $access[$membership->user_id] ?? [];
            usort($projects, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            $rows[] = [
                'membershipId' => $membership->id,
                'userId' => $membership->user_id,
                'name' => $profile['name'],
                'email' => $profile['email'],
                'orgRole' => $membership->role->value,
                'twoFactor' => isset($enrolled[$membership->user_id]),
                'lastActiveAt' => $profile['lastActiveAt'],
                'projects' => $projects,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * The invitations still open: waiting for the inviter to confirm their email, or sent and not yet used or expired.
     *
     * @return list<array{id: string, email: string, orgRole: string, status: string, inviter: string, expiresAt: int|null, projects: list<array{name: string, role: string}>}>
     */
    public function openInvitations(string $organizationId): array
    {
        $invitations = Invitation::query()
            ->with('grants')
            ->whereIn('status', [InvitationStatus::Queued->value, InvitationStatus::Pending->value])
            ->orderByDesc('id')
            ->get();
        $inviters = $this->people->profiles(array_values(array_unique($invitations->map(static fn (Invitation $invitation): string => $invitation->invited_by)->all())));
        $projectNames = $this->projectNames($organizationId);
        $now = $this->clock->now();

        $rows = [];

        foreach ($invitations as $invitation) {
            $projects = [];

            foreach ($invitation->grants as $grant) {
                if (isset($projectNames[$grant->project_id])) {
                    $projects[] = ['name' => $projectNames[$grant->project_id], 'role' => $grant->role->value];
                }
            }

            $rows[] = [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'orgRole' => $invitation->org_role->value,
                // A pending invitation whose time is up reads as expired even before the job marks it.
                'status' => $invitation->status === InvitationStatus::Pending && $invitation->expires_at !== null && $invitation->expires_at <= $now ? 'expired' : $invitation->status->value,
                'inviter' => $inviters[$invitation->invited_by]['name'] ?? 'Someone',
                'expiresAt' => $invitation->expires_at?->getTimestamp(),
                'projects' => $projects,
            ];
        }

        return $rows;
    }

    /**
     * How many members have not turned on two-factor.
     */
    public function withoutTwoFactor(string $organizationId): int
    {
        $ids = OrganizationMembership::query()->get()->map(static fn (OrganizationMembership $membership): string => $membership->user_id)->all();

        return count($ids) - count($this->twoFactor->enrolledAmong(array_values($ids)));
    }

    /**
     * @return array<string, string> project id => name, for the organization's active projects
     */
    private function projectNames(string $organizationId): array
    {
        $ids = ProjectMembership::query()->get()->map(static fn (ProjectMembership $membership): string => $membership->project_id)->unique()->values()->all();
        $names = [];

        foreach ($this->projects->activeAmong($organizationId, array_values($ids)) as $project) {
            $names[$project->id] = $project->name;
        }

        return $names;
    }
}
