<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Modules\Organizations\Domain\OnboardingStep;

final readonly class RecordOnboardingProgressData
{
    private function __construct(public string $organizationId, public OnboardingStep $completed) {}

    public static function planChosen(string $organizationId): self
    {
        return new self($organizationId, OnboardingStep::Plan);
    }

    public static function teamDone(string $organizationId): self
    {
        return new self($organizationId, OnboardingStep::Team);
    }

    public static function readySeen(string $organizationId): self
    {
        return new self($organizationId, OnboardingStep::Ready);
    }
}
