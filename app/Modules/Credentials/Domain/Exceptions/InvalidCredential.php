<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * The presented secret is not a credential: malformed, a wrong checksum or unknown. Answered as 401 INVALID_CREDENTIAL, with
 * the same body whichever it was.
 */
final class InvalidCredential extends ApplicationException
{
    public function __construct()
    {
        parent::__construct('The credential is not valid.');
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIAL';
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
