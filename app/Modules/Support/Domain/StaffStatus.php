<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain;

/**
 * Whether a staff member may use the admin console.
 */
enum StaffStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
