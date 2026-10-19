<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Errors\ApplicationException;

final class IdempotencyKeyReused extends ApplicationException
{
    public function __construct()
    {
        parent::__construct('The idempotency key was already used with a different request.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REUSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
