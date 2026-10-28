<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * A valid kind of credential, but not one this endpoint accepts, for example an SDK key on the management API. Answered as
 * 401 WRONG_CREDENTIAL_TYPE.
 */
final class WrongCredentialType extends ApplicationException
{
    public function __construct(string $message = 'This credential cannot be used here.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'WRONG_CREDENTIAL_TYPE';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public function headers(): array
    {
        return ['WWW-Authenticate' => 'Bearer error="invalid_token"'];
    }
}
