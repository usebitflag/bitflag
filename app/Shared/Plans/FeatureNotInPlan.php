<?php

declare(strict_types=1);

namespace App\Shared\Plans;

use App\Shared\Errors\ApplicationException;

/**
 * The action needs a feature the organization's plan does not include. Answered as 403 FEATURE_NOT_IN_PLAN.
 */
final class FeatureNotInPlan extends ApplicationException
{
    public function __construct(public readonly Feature $feature, string $message = 'Your plan does not include this.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'FEATURE_NOT_IN_PLAN';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['feature' => $this->feature->value];
    }
}
