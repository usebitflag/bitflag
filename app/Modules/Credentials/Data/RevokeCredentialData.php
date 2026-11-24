<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

use App\Modules\Credentials\Domain\RevokeReason;

final readonly class RevokeCredentialData
{
    /** An environment was archived, so its keys go with it. */
    public static function environmentArchived(string $organizationId, string $credentialId): self
    {
        return new self($organizationId, $credentialId, RevokeReason::EnvironmentArchived);
    }

    public function __construct(
        public string $organizationId,
        public string $credentialId,
        public RevokeReason $reason = RevokeReason::Manual,
    ) {}
}
