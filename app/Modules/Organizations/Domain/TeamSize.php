<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * How big the team is, picked while setting up. It is only for understanding who uses BitFlag.
 */
enum TeamSize: string
{
    case Solo = 'solo';
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
}
