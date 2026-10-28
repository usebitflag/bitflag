<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Shared\Capabilities\ProjectRole;

final readonly class InviteGrant
{
    public function __construct(public string $projectId, public ProjectRole $role) {}
}
