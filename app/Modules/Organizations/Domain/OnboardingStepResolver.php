<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * Works out where a person stands in the sign-up wizard from facts about what exists, not from a remembered position, so
 * refreshing or coming back later lands on the right step.
 *
 * A person with no organization is at the first step. Otherwise the wizard concerns the newest organization they created that
 * is not finished: the plan step if billing has one and no plan was chosen, the project step if it has no project, the team
 * step if it has not been done or skipped, and the ready screen once. Everyone else, such as someone who joined by invitation,
 * is never taken through it.
 */
final class OnboardingStepResolver
{
    /**
     * @param  bool  $hasOrganization  the person belongs to at least one organization
     * @param  array{planChosen: bool, hasProject: bool, teamStepDone: bool, readySeen: bool}|null  $created  facts about the newest organization this person created and is still setting up, or null if there is none
     * @param  bool  $planStepExists  whether billing has registered a plan step
     */
    public static function resolve(bool $hasOrganization, ?array $created, bool $planStepExists): ?OnboardingStep
    {
        if (! $hasOrganization) {
            return OnboardingStep::Organization;
        }

        if ($created === null) {
            return null;
        }

        return match (true) {
            $planStepExists && ! $created['planChosen'] => OnboardingStep::Plan,
            ! $created['hasProject'] => OnboardingStep::Project,
            ! $created['teamStepDone'] => OnboardingStep::Team,
            ! $created['readySeen'] => OnboardingStep::Ready,
            default => null,
        };
    }
}
