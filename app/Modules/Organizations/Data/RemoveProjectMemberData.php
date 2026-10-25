<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class RemoveProjectMemberData
{
    public function __construct(public string $organizationId, public string $projectId, public string $membershipId) {}
}
