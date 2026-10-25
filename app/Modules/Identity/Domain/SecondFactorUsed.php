<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Which kind of code a person just spent, if any.
 */
enum SecondFactorUsed
{
    case AuthenticatorCode;
    case RecoveryCode;
    case None;
}
