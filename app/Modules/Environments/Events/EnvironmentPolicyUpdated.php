<?php

declare(strict_types=1);

namespace App\Modules\Environments\Events;

/**
 * A new version of an environment's protection policy was saved. Change sets that were prepared under an older version are
 * rechecked in response.
 */
final readonly class EnvironmentPolicyUpdated
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public int $policyVersion,
    ) {}
}
