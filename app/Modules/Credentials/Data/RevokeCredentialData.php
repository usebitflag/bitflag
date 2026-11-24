<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

use App\Modules\Credentials\Domain\RevokeReason;

final readonly class RevokeCredentialData
{
    public function __construct(
        public string $organizationId,
        public string $credentialId,
        public RevokeReason $reason = RevokeReason::Manual,
    ) {}
}
