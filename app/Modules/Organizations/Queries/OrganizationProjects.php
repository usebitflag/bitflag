<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Environments\Queries\EnvironmentLookup;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Queries\ProjectLookup;

/**
 * The organization's projects for the Projects page: how many environments and members each has, and whether the person asking
 * belongs to it (an Org Owner who does not can join as Owner).
 */
final class OrganizationProjects
{
    public function __construct(
        private readonly ProjectLookup $projects,
        private readonly EnvironmentLookup $environments,
    ) {}

    /**
     * @return list<array{id: string, key: string, name: string, environments: int, members: int, isMember: bool}>
     */
    public function for(string $organizationId, string $userId): array
    {
        $memberships = ProjectMembership::query()->get();
        $ids = $memberships->map(static fn (ProjectMembership $membership): string => $membership->project_id)->unique()->values()->all();
        $rows = [];

        foreach ($this->projects->allIn($organizationId) as $project) {
            $rows[] = [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'environments' => count($this->environments->activeFor($organizationId, $project->id)),
                'members' => $memberships->where('project_id', $project->id)->count(),
                'isMember' => $memberships->where('project_id', $project->id)->where('user_id', $userId)->isNotEmpty(),
            ];
        }

        return $rows;
    }
}
