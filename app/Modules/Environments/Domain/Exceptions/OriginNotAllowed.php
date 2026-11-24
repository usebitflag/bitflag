<?php

declare(strict_types=1);

namespace App\Modules\Environments\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

final class OriginNotAllowed extends ApplicationException
{
    public function __construct()
    {
        parent::__construct('This origin is not allowed to use the browser key.');
    }

    public function errorCode(): string
    {
        return 'ORIGIN_NOT_ALLOWED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
