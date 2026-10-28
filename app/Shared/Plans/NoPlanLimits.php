<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Used until billing exists: nothing is limited.
 */
final class NoPlanLimits implements EnforcePlanLimit
{
    public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void {}
}
