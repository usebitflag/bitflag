<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * The steps of the sign-up wizard, after the account itself: the organization, a plan (once billing registers that step),
 * the first project, inviting the team, and the ready screen.
 */
enum OnboardingStep: string
{
    case Organization = 'organization';
    case Plan = 'plan';
    case Project = 'project';
    case Team = 'team';
    case Ready = 'ready';
}
