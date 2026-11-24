<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain\Exceptions;

use App\Shared\Errors\ApplicationException;

/**
 * The presented secret was a credential that is revoked, expired or rotated out. The caller already holds the secret, so
 * saying so discloses nothing and lets the CLI explain. Answered as 401 CREDENTIAL_REVOKED.
 */
final class RevokedCredential extends ApplicationException
{
    public function __construct()
    {
        parent::__construct('The credential has been revoked or has expired.');
    }

    public function errorCode(): string
    {
        return 'CREDENTIAL_REVOKED';
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
