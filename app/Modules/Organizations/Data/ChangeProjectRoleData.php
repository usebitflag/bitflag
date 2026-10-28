<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Shared\Capabilities\ProjectRole;

final readonly class ChangeProjectRoleData
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $membershipId,
        public ProjectRole $role,
        public int $expectedVersion,
    ) {}
}
