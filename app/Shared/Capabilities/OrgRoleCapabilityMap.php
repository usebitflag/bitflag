<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * Which organization capabilities each organization role holds. A role that only exists in a design (such as a billing
 * administrator) would map to org.billing.manage alone; none is built.
 */
final class OrgRoleCapabilityMap
{
    /**
     * @return list<Capability>
     */
    public static function for(OrgRole $role): array
    {
        return match ($role) {
            OrgRole::Owner => [
                Capability::OrgRead,
                Capability::OrgManage,
                Capability::OrgMembersManage,
                Capability::OrgBillingManage,
                Capability::ProjectsCreate,
            ],
            OrgRole::Member => [
                Capability::OrgRead,
            ],
        };
    }

    public static function allows(OrgRole $role, Capability $capability): bool
    {
        return in_array($capability, self::for($role), true);
    }
}
