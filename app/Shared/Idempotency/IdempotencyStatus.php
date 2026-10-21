<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * A stored idempotency key either holds a request that is still running or has its stored result.
 */
enum IdempotencyStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
