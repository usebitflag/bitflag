<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Domain\RevokeReason;
use App\Shared\Actors\Actor;

/**
 * Revokes an old key whose rotation overlap has ended. System-only: the scheduler calls it for each key that is due.
 */
final class CompleteRotation
{
    public function __construct(private readonly RevokeCredential $revoke) {}

    public function handle(string $organizationId, string $credentialId): void
    {
        $this->revoke->handle(Actor::system('credential rotation'), new RevokeCredentialData($organizationId, $credentialId, RevokeReason::RotationCompleted));
    }
}
