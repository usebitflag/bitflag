<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\OrganizationMfaPolicy;
use App\Modules\Organizations\Queries\OrganizationSecurityPolicies;

/**
 * Asks the organizations a person belongs to whether any of them requires two-factor sign-in.
 */
final class OrganizationsMfaPolicy implements OrganizationMfaPolicy
{
    public function __construct(private readonly OrganizationSecurityPolicies $policies) {}

    public function organizationsRequiringMfa(string $userId): array
    {
        return $this->policies->organizationsRequiringMfa($userId);
    }
}
