<?php

declare(strict_types=1);

namespace App\Shared\Errors;

/**
 * The resource does not exist, or exists outside the caller's scope. Both look the same on purpose.
 */
final class ResourceNotFound extends ApplicationException
{
    public function __construct(string $message = 'The requested resource was not found.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
