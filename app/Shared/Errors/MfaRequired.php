<?php

declare(strict_types=1);

namespace App\Shared\Errors;

/**
 * The organization requires two-factor sign-in and the person has not set it up yet.
 */
final class MfaRequired extends ApplicationException
{
    public function __construct(public readonly string $organizationName)
    {
        parent::__construct("{$organizationName} requires two-factor sign-in. Turn it on in your account security settings.");
    }

    public function errorCode(): string
    {
        return 'MFA_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
