<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * The organization only allows people with email addresses at certain domains, and this address is at another.
 */
final class DomainNotAllowed extends ApplicationException
{
    public function __construct(string $message = 'This organization only allows email addresses at certain domains.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'DOMAIN_NOT_ALLOWED';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
