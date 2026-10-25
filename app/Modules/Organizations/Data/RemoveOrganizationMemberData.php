<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class RemoveOrganizationMemberData
{
    public function __construct(public string $organizationId, public string $membershipId) {}
}
