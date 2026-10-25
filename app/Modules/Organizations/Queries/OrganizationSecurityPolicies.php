<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Shared\Tenancy\Tenancy;

/**
 * What an organization's security settings mean for the people in it. Sign-in and account screens ask here instead of
 * reading organizations themselves.
 */
final class OrganizationSecurityPolicies
{
    /**
     * The names of the organizations the person belongs to that require two-factor sign-in.
     *
     * @return list<string>
     */
    public function organizationsRequiringMfa(string $userId): array
    {
        return Tenancy::crossTenant('security policy lookup spans the person\'s organizations', function () use ($userId): array {
            $ids = OrganizationMembership::query()->where('user_id', $userId)->pluck('organization_id')->all();

            $names = Organization::query()
                ->whereIn('id', $ids)
                ->where('require_mfa', true)
                ->orderBy('name')
                ->get()
                ->map(static fn (Organization $organization): string => $organization->name)
                ->all();

            return array_values($names);
        });
    }

    /**
     * The idle limit the organization chose, in minutes, or null for the platform's.
     */
    public function idleMinutesFor(string $slug): ?int
    {
        return Organization::query()->where('slug', $slug)->first()?->session_idle_timeout_minutes;
    }
}
