<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * What billing knows about an organization that other modules need to decide. Billing provides the real answers; until it
 * does, nobody has a paid subscription.
 */
interface BillingStatus
{
    public function hasActivePaidSubscription(string $organizationId): bool;
}
