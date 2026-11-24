<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

/**
 * The shape a project asks for in new flag keys. Stored values never change.
 */
enum FlagKeyFormat: string
{
    case Kebab = 'kebab';
    case Snake = 'snake';
    case Any = 'any';
}
