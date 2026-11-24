<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

final class ScopeMissing extends ApplicationException
{
    public function __construct()
    {
        parent::__construct('This credential does not have the required scope.');
    }

    public function errorCode(): string
    {
        return 'SCOPE_MISSING';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
