<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * Where an organization is in its lifecycle. Only active organizations can be used.
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case DeletionScheduled = 'deletion_scheduled';
}
