<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\SessionPolicyLookup;
use App\Modules\Organizations\Queries\OrganizationSecurityPolicies;

/**
 * Reads the idle limit an organization has chosen for the people who open it.
 */
final class OrganizationsSessionPolicy implements SessionPolicyLookup
{
    public function __construct(private readonly OrganizationSecurityPolicies $policies) {}

    public function idleMinutesFor(string $organizationSlug): ?int
    {
        return $this->policies->idleMinutesFor($organizationSlug);
    }
}
