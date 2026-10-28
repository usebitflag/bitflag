<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;

/**
 * Whether an organization is open for work. A suspended organization, or one waiting to be deleted, takes no new projects.
 */
final class OrganizationStatuses
{
    public function isActive(string $organizationId): bool
    {
        return Organization::query()->whereKey($organizationId)->where('status', OrganizationStatus::Active->value)->exists();
    }
}
