<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * Where an invitation stands.
 *
 *   queued ──inviter confirms email──▶ pending ──accept──▶ accepted
 *     │                                  │ ├─resend (new link, new 7 days)─▶ pending
 *     └──revoke──▶ revoked ◀──revoke─────┘ └─time passes─▶ expired
 */
enum InvitationStatus: string
{
    case Queued = 'queued';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /** An invitation that has not been used, withdrawn or run out. */
    public function isOpen(): bool
    {
        return $this === self::Queued || $this === self::Pending;
    }
}
