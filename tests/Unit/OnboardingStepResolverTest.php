<?php

declare(strict_types=1);

use App\Modules\Organizations\Domain\OnboardingStep;
use App\Modules\Organizations\Domain\OnboardingStepResolver;

function facts(array $change = []): array
{
    return ['planChosen' => false, 'hasProject' => false, 'teamStepDone' => false, 'readySeen' => false, ...$change];
}

it('starts a person with no organization at the organization step', function () {
    expect(OnboardingStepResolver::resolve(false, null, false))->toBe(OnboardingStep::Organization);
});

it('does not take a person who belongs to an organization they did not create through the wizard', function () {
    expect(OnboardingStepResolver::resolve(true, null, false))->toBeNull()
        ->and(OnboardingStepResolver::resolve(true, null, true))->toBeNull();
});

it('goes to the project step when the organization has no project', function () {
    expect(OnboardingStepResolver::resolve(true, facts(), false))->toBe(OnboardingStep::Project);
});

it('goes to the plan step first only when billing has one and no plan was chosen', function () {
    expect(OnboardingStepResolver::resolve(true, facts(), true))->toBe(OnboardingStep::Plan)
        ->and(OnboardingStepResolver::resolve(true, facts(['planChosen' => true]), true))->toBe(OnboardingStep::Project)
        ->and(OnboardingStepResolver::resolve(true, facts(), false))->toBe(OnboardingStep::Project);
});

it('goes to the team step when there is a project and the step is not done', function () {
    expect(OnboardingStepResolver::resolve(true, facts(['hasProject' => true]), false))->toBe(OnboardingStep::Team);
});

it('shows the ready screen once everything else is done, and then finishes', function () {
    $done = facts(['hasProject' => true, 'teamStepDone' => true]);

    expect(OnboardingStepResolver::resolve(true, $done, false))->toBe(OnboardingStep::Ready)
        ->and(OnboardingStepResolver::resolve(true, [...$done, 'readySeen' => true], false))->toBeNull();
});

it('works the step out from facts, whatever order things happened in', function () {
    // A project created some other way, before the plan step was ever reached.
    expect(OnboardingStepResolver::resolve(true, facts(['hasProject' => true, 'teamStepDone' => true]), false))->toBe(OnboardingStep::Ready);
});
