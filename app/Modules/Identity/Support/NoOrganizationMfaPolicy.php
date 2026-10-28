<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\OrganizationMfaPolicy;

/**
 * Used until organizations can require two-factor.
 */
final class NoOrganizationMfaPolicy implements OrganizationMfaPolicy
{
    public function organizationsRequiringMfa(string $userId): array
    {
        return [];
    }
}
