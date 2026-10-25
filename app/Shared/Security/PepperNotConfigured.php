<?php

declare(strict_types=1);

namespace App\Shared\Security;

use RuntimeException;

/**
 * A secret cannot be hashed or looked up because the pepper it needs is missing or malformed. The message names the
 * setting, never a value.
 */
final class PepperNotConfigured extends RuntimeException
{
    public static function missing(int $pepperId): self
    {
        return new self("Pepper {$pepperId} is not configured. Set BITFLAG_CREDENTIAL_PEPPER_{$pepperId}.");
    }

    public static function malformed(int $pepperId): self
    {
        return new self("Pepper {$pepperId} must be 32 random bytes, base64-encoded (BITFLAG_CREDENTIAL_PEPPER_{$pepperId}).");
    }
}
