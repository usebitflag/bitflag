<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Domain\SignInThrottle;

/**
 * What the sign-in screens say when a sign-in is refused. A failure always says the same thing, and a lock says the same
 * for an address with an account and one without.
 */
final class SignInMessages
{
    public const FAILED = "That email and password don't match. Check them and try again.";

    public const LOCKED = SignInThrottle::LOCKED_MESSAGE;

    public const TOO_MANY_CODES = 'Too many wrong codes. Sign in again.';
}
