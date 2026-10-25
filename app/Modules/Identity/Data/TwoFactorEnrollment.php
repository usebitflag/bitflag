<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * The shared secret and the link an authenticator app reads from the QR code. Shown to the person once, while they enroll.
 */
final readonly class TwoFactorEnrollment
{
    public function __construct(public string $secret, public string $uri) {}
}
