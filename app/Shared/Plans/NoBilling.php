<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Used until billing exists: no organization has a paid subscription.
 */
final class NoBilling implements BillingStatus
{
    public function hasActivePaidSubscription(string $organizationId): bool
    {
        return false;
    }
}
