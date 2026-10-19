<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Projects\Data\ProjectSummary;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;

final class ProjectLookup
{
    public function byKey(string $organizationId, string $key): ?ProjectSummary
    {
        return $this->first($organizationId, fn ($query) => $query->where('key', $key));
    }

    public function byId(string $organizationId, string $projectId): ?ProjectSummary
    {
        return $this->first($organizationId, fn ($query) => $query->whereKey($projectId));
    }

    /**
     * The active projects among the given IDs, by name.
     *
     * @param  list<string>  $projectIds
     * @return list<ProjectSummary>
     */
    public function activeAmong(string $organizationId, array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return Tenancy::crossTenant('project lookup resolves the portal scope', fn (): array => array_values(Project::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $projectIds)
            ->where('status', ProjectStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): ProjectSummary => $this->summary($project))
            ->all()));
    }

    /**
     * @param  callable(Builder<Project>): Builder<Project>  $narrow
     */
    private function first(string $organizationId, callable $narrow): ?ProjectSummary
    {
        return Tenancy::crossTenant('project lookup resolves the portal scope', function () use ($organizationId, $narrow): ?ProjectSummary {
            $project = $narrow(Project::query()->where('organization_id', $organizationId))->first();

            return $project === null ? null : $this->summary($project);
        });
    }

    private function summary(Project $project): ProjectSummary
    {
        return new ProjectSummary($project->id, $project->organization_id, $project->key, $project->name, $project->status === ProjectStatus::Active);
    }
}
