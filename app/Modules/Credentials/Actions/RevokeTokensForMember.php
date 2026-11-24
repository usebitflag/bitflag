<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Domain\RevokeReason;
use App\Modules\Credentials\Queries\PersonalTokens;
use App\Shared\Actors\Actor;

/**
 * Revokes a person's personal tokens when what they depended on is gone: their membership of a project (that project's
 * tokens), of an organization (all tokens in it), or their account (every token). Management keys belong to the project and
 * keep working. System-only: listeners and the account deletion call it.
 */
final class RevokeTokensForMember
{
    public function __construct(
        private readonly PersonalTokens $tokens,
        private readonly RevokeCredential $revoke,
    ) {}

    /** Every personal token of a person whose account is being deleted, in every organization. */
    public function whenAccountDeleted(string $userId): int
    {
        return $this->handle($userId, RevokeReason::AccountDeleted);
    }

    /** @return int how many tokens were revoked */
    public function handle(string $userId, RevokeReason $reason, ?string $organizationId = null, ?string $projectId = null): int
    {
        $rows = $this->tokens->workingFor($userId, $organizationId, $projectId);

        foreach ($rows as $row) {
            $this->revoke->handle(Actor::system('member change'), new RevokeCredentialData($row['organizationId'], $row['credentialId'], $reason));
        }

        return count($rows);
    }
}
