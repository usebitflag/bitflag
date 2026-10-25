<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Projects\Queries\ProjectLookup;

/**
 * How many projects a person can open in an organization, for the organization chooser and switcher.
 */
final class ProjectCounts
{
    public function __construct(
        private readonly MembershipLookup $memberships,
        private readonly ProjectLookup $projects,
    ) {}

    public function for(string $userId, string $organizationId): int
    {
        return count($this->projects->activeAmong($organizationId, $this->memberships->projectIdsFor($userId, $organizationId)));
    }
}
