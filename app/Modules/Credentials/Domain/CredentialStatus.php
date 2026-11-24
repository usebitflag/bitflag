<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

enum CredentialStatus: string
{
    case Active = 'active';
    case Rotating = 'rotating';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
