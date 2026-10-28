<?php

declare(strict_types=1);

namespace App\Shared\Plans;

use App\Shared\Errors\ApplicationException;

/**
 * The action would take the organization over its plan's limit. Answered as 403 PLAN_LIMIT, naming the limit.
 */
final class PlanLimitReached extends ApplicationException
{
    public function __construct(public readonly LimitKey $limit, string $message = 'Your plan has reached this limit.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'PLAN_LIMIT';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['limit' => $this->limit->value];
    }
}
