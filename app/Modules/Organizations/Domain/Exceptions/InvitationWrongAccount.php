<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * Someone signed in as a different person opened an invitation. Nothing is used up.
 */
final class InvitationWrongAccount extends ApplicationException
{
    public function __construct(string $message = 'This invitation was sent to a different email address.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'INVITATION_WRONG_ACCOUNT';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
