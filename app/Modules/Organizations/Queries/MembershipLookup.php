<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Data\OrganizationAccess;
use App\Modules\Organizations\Data\OrganizationSummary;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads who belongs to which organization and project. A person only ever sees organizations and projects they are a
 * member of, and a lookup that finds nothing looks the same as one for something that does not exist.
 */
final class MembershipLookup
{
    public function organizationBySlug(string $userId, string $slug): ?OrganizationAccess
    {
        return $this->organizations($userId, fn ($query) => $query->where('organizations.slug', $slug))[0] ?? null;
    }

    public function organizationById(string $userId, string $organizationId): ?OrganizationAccess
    {
        return $this->organizations($userId, fn ($query) => $query->where('organizations.id', $organizationId))[0] ?? null;
    }

    /**
     * Every organization the person belongs to, by name.
     *
     * @return list<OrganizationAccess>
     */
    public function organizationsFor(string $userId): array
    {
        return $this->organizations($userId, fn ($query) => $query);
    }

    public function projectRole(string $userId, string $organizationId, string $projectId): ?ProjectRole
    {
        return Tenancy::crossTenant('membership lookup resolves the portal scope', fn (): ?ProjectRole => ProjectMembership::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('user_id', $userId)
            ->first()?->role);
    }

    /**
     * @return list<string> the projects of the organization the person is a member of
     */
    public function projectIdsFor(string $userId, string $organizationId): array
    {
        return Tenancy::crossTenant('membership lookup lists readable projects', fn (): array => array_values(ProjectMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->get()
            ->map(static fn (ProjectMembership $membership): string => $membership->project_id)
            ->all()));
    }

    /**
     * @param  callable(Builder<Organization>): Builder<Organization>  $narrow
     * @return list<OrganizationAccess>
     */
    private function organizations(string $userId, callable $narrow): array
    {
        return Tenancy::crossTenant('membership lookup resolves the portal scope', function () use ($userId, $narrow): array {
            $memberships = OrganizationMembership::query()->where('user_id', $userId)->get()->keyBy('organization_id');

            if ($memberships->isEmpty()) {
                return [];
            }

            $organizations = $narrow(Organization::query()->whereIn('organizations.id', $memberships->keys()->all()))
                ->orderBy('organizations.name')
                ->get();

            $access = [];

            foreach ($organizations as $organization) {
                $membership = $memberships->get($organization->id);

                if ($membership instanceof OrganizationMembership) {
                    $access[] = new OrganizationAccess(
                        new OrganizationSummary($organization->id, $organization->slug, $organization->name, $organization->status),
                        $membership->role,
                    );
                }
            }

            return $access;
        });
    }
}
