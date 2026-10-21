<?php

declare(strict_types=1);

namespace App\Http\Portal;

use App\Modules\Environments\Queries\EnvironmentLookup;
use App\Modules\Organizations\Queries\MembershipLookup;
use App\Modules\Projects\Queries\ProjectLookup;

/**
 * Resolves portal URL segments (organization slug, project key, environment key) to a scope for one person. Returns null
 * when anything is missing, archived, or the person is not a member, so a caller cannot tell those cases apart.
 */
final class PortalScopeResolver
{
    public function __construct(
        private readonly MembershipLookup $memberships,
        private readonly ProjectLookup $projects,
        private readonly EnvironmentLookup $environments,
    ) {}

    public function resolve(string $userId, string $organizationSlug, ?string $projectKey = null, ?string $environmentKey = null): ?PortalScope
    {
        $access = $this->memberships->organizationBySlug($userId, $organizationSlug);

        if ($access === null) {
            return null;
        }

        if ($projectKey === null) {
            return new PortalScope($access->organization, $access->role);
        }

        $project = $this->projects->byKey($access->organization->id, $projectKey);
        $projectRole = $project === null ? null : $this->memberships->projectRole($userId, $access->organization->id, $project->id);

        if ($project === null || $projectRole === null || ! $project->active) {
            return null;
        }

        if ($environmentKey === null) {
            return new PortalScope($access->organization, $access->role, $project, $projectRole);
        }

        $environment = $this->environments->byKey($access->organization->id, $project->id, $environmentKey);

        if ($environment === null || ! $environment->active) {
            return null;
        }

        return new PortalScope($access->organization, $access->role, $project, $projectRole, $environment);
    }

    /**
     * The first place a person can land inside an organization: their remembered project and environment when they still
     * have access, otherwise the first readable project by name and its first environment by position.
     */
    public function landing(string $userId, string $organizationId, ?string $rememberedProjectId = null, ?string $rememberedEnvironmentId = null): ?PortalScope
    {
        $access = $this->memberships->organizationById($userId, $organizationId);

        if ($access === null) {
            return null;
        }

        $projects = $this->projects->activeAmong($access->organization->id, $this->memberships->projectIdsFor($userId, $access->organization->id));

        $remembered = array_values(array_filter($projects, static fn ($project): bool => $project->id === $rememberedProjectId));
        $project = $remembered[0] ?? $projects[0] ?? null;

        if ($project === null) {
            return null;
        }

        $environments = $this->environments->activeFor($access->organization->id, $project->id);
        $rememberedEnvironment = array_values(array_filter($environments, static fn ($environment): bool => $project->id === $rememberedProjectId && $environment->id === $rememberedEnvironmentId));
        $environment = $rememberedEnvironment[0] ?? $environments[0] ?? null;

        if ($environment === null) {
            return null;
        }

        $role = $this->memberships->projectRole($userId, $access->organization->id, $project->id);

        return $role === null ? null : new PortalScope($access->organization, $access->role, $project, $role, $environment);
    }
}
