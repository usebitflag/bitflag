<?php

declare(strict_types=1);

namespace App\Shared\Errors;

/**
 * The action needs a confirmed email address (inviting people, issuing keys, publishing) and the person has not
 * confirmed theirs yet.
 */
final class EmailNotVerified extends ApplicationException
{
    public function __construct(string $message = 'Confirm your email address to do this.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'EMAIL_NOT_VERIFIED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
