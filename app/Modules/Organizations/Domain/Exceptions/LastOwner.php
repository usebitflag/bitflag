<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * The change would leave an organization or a project with nobody in charge. Every project keeps at least one Owner and
 * every organization at least one Org Owner.
 */
final class LastOwner extends ApplicationException
{
    public function __construct(string $message = 'This is the last owner. Make someone else an owner first.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'LAST_OWNER';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
