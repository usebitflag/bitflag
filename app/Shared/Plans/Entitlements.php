<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Says what the organization's plan includes. Billing provides the real answers; until it does, every feature is included.
 */
interface Entitlements
{
    /**
     * @throws FeatureNotInPlan when the plan does not include the feature
     */
    public function assertAllows(string $organizationId, Feature $feature): void;

    public function allows(string $organizationId, Feature $feature): bool;
}
