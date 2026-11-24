<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

use DateTimeImmutable;

/**
 * The result of a rotation: the replacement, whose secret is shown once, and when the old credential stops working.
 */
final readonly class RotatedCredential
{
    public function __construct(
        public string $oldCredentialId,
        public string $replacementId,
        public PlaintextSecret $secret,
        public DateTimeImmutable $oldStopsAt,
    ) {}
}
