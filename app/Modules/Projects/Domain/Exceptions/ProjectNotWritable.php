<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * The project is archived or waiting to be deleted, so its configuration and keys cannot change. Answered as
 * 403 PROJECT_ARCHIVED or 403 PROJECT_DELETION_SCHEDULED. Rotating and revoking keys are never refused for this reason.
 */
final class ProjectNotWritable extends ApplicationException
{
    public function __construct(public readonly string $projectStatus)
    {
        parent::__construct($projectStatus === 'archived' ? 'This project is archived, so it is read-only.' : 'This project is scheduled for deletion, so it is read-only.');
    }

    public function errorCode(): string
    {
        return $this->projectStatus === 'archived' ? 'PROJECT_ARCHIVED' : 'PROJECT_DELETION_SCHEDULED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
