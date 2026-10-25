<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * The invitation cannot be used: it does not exist, was withdrawn, ran out or was already used. One answer for all of
 * them, so the link does not say which.
 */
final class InvitationUnavailable extends ApplicationException
{
    public function __construct(string $message = 'This invitation is not available.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'INVITATION_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
