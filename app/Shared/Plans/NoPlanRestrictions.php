<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Used until billing exists: nothing is read-only.
 */
final class NoPlanRestrictions implements PlanRestrictionGate
{
    public function assertConfigWritable(string $organizationId, string $projectId, ?string $environmentId = null): void {}
}
