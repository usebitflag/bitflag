<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Refuses a configuration change in a project the plan has made read-only (for example after a downgrade). Rotating and
 * revoking credentials never go through it. Billing provides the real rules; until it does, nothing is restricted.
 */
interface PlanRestrictionGate
{
    /**
     * @throws PlanLimitReached when the project or environment is read-only under the plan
     */
    public function assertConfigWritable(string $organizationId, string $projectId, ?string $environmentId = null): void;
}
