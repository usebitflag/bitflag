<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The kind of second factor. Only authenticator-app codes (TOTP) exist at launch.
 */
enum MfaFactorType: string
{
    case Totp = 'totp';
}
