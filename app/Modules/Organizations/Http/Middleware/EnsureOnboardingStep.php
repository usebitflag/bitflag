<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Middleware;

use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Organizations\Queries\OnboardingState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the sign-up wizard on the step the facts say a person is at: asking for any other step, or another organization's,
 * sends them to the right one. `onboarding.step:project` names the step the route belongs to.
 */
final class EnsureOnboardingStep
{
    public function __construct(
        private readonly CurrentPerson $currentPerson,
        private readonly OnboardingState $state,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $step): Response
    {
        $person = $this->currentPerson->find();

        if ($person === null) {
            return redirect('/signin');
        }

        $status = $this->state->for($person->id);
        $slug = $request->route('organization');
        $slugMatches = $slug === null || $slug === $status->organizationSlug;

        if ($status->step?->value === $step && $slugMatches) {
            return $next($request);
        }

        return redirect($status->path());
    }
}
