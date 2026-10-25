<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Refuses an action that would take an organization over its plan's limit. Billing provides the real rules; until it does,
 * nothing is limited. Actions that create a limited thing call it before writing.
 */
interface EnforcePlanLimit
{
    /**
     * @param  int  $adding  how many of the limited thing the action adds
     *
     * @throws PlanLimitReached when the limit would be exceeded
     */
    public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void;
}
