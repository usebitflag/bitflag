<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * A person confirmed their email address. Invitations they sent while unconfirmed are released in response.
 */
final readonly class EmailVerified
{
    public function __construct(public string $userId) {}
}
