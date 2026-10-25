<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Modules\Organizations\Domain\OnboardingStep;

/**
 * Where a person is in the wizard, and the organization it concerns. `step` is null when they are done.
 */
final readonly class OnboardingStatus
{
    public function __construct(
        public ?OnboardingStep $step,
        public ?string $organizationId = null,
        public ?string $organizationSlug = null,
        public ?string $organizationName = null,
    ) {}

    public function path(): string
    {
        return match ($this->step) {
            null => '/',
            OnboardingStep::Organization => '/onboarding/organization',
            default => "/onboarding/{$this->organizationSlug}/{$this->step->value}",
        };
    }
}
