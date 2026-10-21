<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

/**
 * Where a project is in its lifecycle. Only active projects open in the portal.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case DeletionScheduled = 'deletion_scheduled';
    case Deleted = 'deleted';
}
