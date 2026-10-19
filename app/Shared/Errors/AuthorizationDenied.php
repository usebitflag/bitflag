<?php

declare(strict_types=1);

namespace App\Shared\Errors;

/**
 * The actor is known but lacks the capability for this action in this scope.
 */
final class AuthorizationDenied extends ApplicationException
{
    public function __construct(string $message = 'You do not have permission to do this.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'FORBIDDEN';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
