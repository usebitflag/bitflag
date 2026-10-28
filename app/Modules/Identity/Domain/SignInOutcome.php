<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * How a password sign-in attempt ended. Only `Authenticated` means the person may be signed in; `TwoFactorRequired`
 * means the password was right but a second factor is still owed.
 */
enum SignInOutcome
{
    case Authenticated;
    case TwoFactorRequired;
    case Failed;
    case Locked;
}
