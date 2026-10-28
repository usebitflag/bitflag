<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Data\OnboardingStatus;
use App\Modules\Organizations\Domain\OnboardingStepResolver;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Tenancy\Tenancy;

/**
 * Reads the facts the wizard resumes from. The step is worked out each time, never stored.
 */
final class OnboardingState
{
    public function __construct(private readonly ProjectLookup $projects) {}

    public function for(string $userId): OnboardingStatus
    {
        return Tenancy::crossTenant('onboarding looks across the person\'s organizations', function () use ($userId): OnboardingStatus {
            $hasOrganization = OrganizationMembership::query()->where('user_id', $userId)->exists();
            $organization = Organization::query()->where('created_by', $userId)->orderByDesc('id')->get()
                ->first(fn (Organization $candidate): bool => $this->isUnfinished($candidate));

            $facts = $organization === null ? null : $this->facts($organization);
            $step = OnboardingStepResolver::resolve($hasOrganization, $facts, config()->boolean('bitflag.onboarding.plan_step'));

            return new OnboardingStatus($step, $organization?->id, $organization?->slug, $organization?->name);
        });
    }

    private function isUnfinished(Organization $organization): bool
    {
        return ! isset($organization->onboarding['ready_seen_at']);
    }

    /**
     * @return array{planChosen: bool, hasProject: bool, teamStepDone: bool, readySeen: bool}
     */
    private function facts(Organization $organization): array
    {
        return [
            'planChosen' => isset($organization->onboarding['plan_chosen_at']),
            'hasProject' => $this->projects->oldest($organization->id) !== null,
            'teamStepDone' => isset($organization->onboarding['team_step_done_at']),
            'readySeen' => isset($organization->onboarding['ready_seen_at']),
        ];
    }
}
