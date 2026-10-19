<?php

declare(strict_types=1);

namespace App\Modules\Environments\Domain;

/**
 * Whether an environment can be worked in.
 */
enum EnvironmentStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
