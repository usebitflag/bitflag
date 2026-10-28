<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\User;
use App\Shared\Errors\EmailNotVerified;

/**
 * Whether a person has confirmed their email. Actions that need a confirmed address (inviting, issuing keys,
 * publishing) call `assertVerified`; the portal shows a banner until it is true.
 */
final class EmailVerification
{
    public function isVerified(string $userId): bool
    {
        return User::query()->whereKey($userId)->whereNotNull('email_verified_at')->exists();
    }

    /**
     * @throws EmailNotVerified
     */
    public function assertVerified(string $userId): void
    {
        if (! $this->isVerified($userId)) {
            throw new EmailNotVerified;
        }
    }
}
