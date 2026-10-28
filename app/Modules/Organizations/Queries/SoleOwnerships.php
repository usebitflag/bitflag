<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Tenancy\Tenancy;

/**
 * The organizations a person alone keeps going: those where they are the only Org Owner. Such a person cannot leave until
 * someone else is made an owner, or the organization is deleted.
 */
final class SoleOwnerships
{
    /**
     * The names of the organizations where this person is the only Org Owner.
     *
     * @return list<string>
     */
    public function organizationNames(string $userId): array
    {
        return Tenancy::crossTenant('account deletion looks across the person\'s organizations', function () use ($userId): array {
            $owned = OrganizationMembership::query()->where('user_id', $userId)->where('role', OrgRole::Owner->value)->get();
            $names = [];

            foreach ($owned as $membership) {
                $owners = OrganizationMembership::query()->where('organization_id', $membership->organization_id)->where('role', OrgRole::Owner->value)->count();

                if ($owners <= 1) {
                    $names[] = Organization::query()->findOrFail($membership->organization_id)->name;
                }
            }

            sort($names);

            return $names;
        });
    }

    /**
     * Every organization the person belongs to, as ids.
     *
     * @return list<string>
     */
    public function organizationIds(string $userId): array
    {
        $ids = Tenancy::crossTenant('account deletion looks across the person\'s organizations', fn (): array => OrganizationMembership::query()
            ->where('user_id', $userId)
            ->get()
            ->map(static fn (OrganizationMembership $membership): string => $membership->organization_id)
            ->all());

        return array_values($ids);
    }
}
