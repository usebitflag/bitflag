<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

final readonly class RotateCredentialData
{
    /**
     * @param  int  $overlapSeconds  how long the old credential keeps working: 1 hour, 24 hours or 7 days
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public string $credentialId,
        public int $overlapSeconds,
    ) {}
}
