<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Used until billing exists: every feature is included.
 */
final class AllFeaturesIncluded implements Entitlements
{
    public function assertAllows(string $organizationId, Feature $feature): void {}

    public function allows(string $organizationId, Feature $feature): bool
    {
        return true;
    }
}
