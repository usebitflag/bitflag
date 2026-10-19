<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Shared\Capabilities\OrgRole;

/**
 * An organization together with the signed-in person's role in it.
 */
final readonly class OrganizationAccess
{
    public function __construct(public OrganizationSummary $organization, public OrgRole $role) {}
}
