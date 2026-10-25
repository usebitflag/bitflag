<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Shared\Capabilities\OrgRole;

final readonly class ChangeOrganizationRoleData
{
    public function __construct(public string $organizationId, public string $membershipId, public OrgRole $role) {}
}
