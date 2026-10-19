<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Errors\ApplicationException;

final class IdempotencyInProgress extends ApplicationException
{
    public function __construct()
    {
        parent::__construct('A request with this idempotency key is still being processed.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_IN_PROGRESS';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function headers(): array
    {
        return ['Retry-After' => '1'];
    }
}
