<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Whether a person's account may sign in and act. A deactivated account holds no capabilities anywhere.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Deactivated = 'deactivated';
}
