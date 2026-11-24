<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

/**
 * A credential just made, with the one chance to read its secret.
 */
final readonly class IssuedCredential
{
    public function __construct(
        public string $credentialId,
        public PlaintextSecret $secret,
    ) {}
}
