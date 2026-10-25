<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Http\Portal\PortalScopeResolver;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Organizations\Queries\MembershipLookup;
use App\Modules\Organizations\Queries\OnboardingState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The redirects that lead a person to a working page: from the site root, from an organization, from a project.
 */
final class HomeController
{
    public function __construct(
        private readonly CurrentPerson $currentPerson,
        private readonly PortalScopeResolver $resolver,
        private readonly MembershipLookup $memberships,
        private readonly OnboardingState $onboarding,
    ) {}

    /** GET / */
    public function root(Request $request): RedirectResponse
    {
        $person = $this->currentPerson->find();

        if ($person === null) {
            return redirect()->route('login');
        }

        // Someone still setting up goes back to the step they were on; someone with no organization starts the wizard.
        $onboarding = $this->onboarding->for($person->id);

        if ($onboarding->step !== null) {
            return redirect($onboarding->path());
        }

        // Right after signing in, a person in several organizations picks one, unless they asked not to be asked again.
        $justSignedIn = $request->session()->pull('signin.choose_organization') === true;

        if ($justSignedIn && ! $person->rememberOrgChoice && count($this->memberships->organizationsFor($person->id)) > 1) {
            return redirect()->route('organizations.choose');
        }

        // The remembered scope first, then the first organization the person can work in.
        $organizationIds = array_filter([$person->lastOrganizationId]);

        foreach ($this->memberships->organizationsFor($person->id) as $access) {
            $organizationIds[] = $access->organization->id;
        }

        foreach (array_unique($organizationIds) as $organizationId) {
            $remembered = $organizationId === $person->lastOrganizationId;
            $scope = $this->resolver->landing($person->id, $organizationId, $remembered ? $person->lastProjectId : null, $remembered ? $person->lastEnvironmentId : null);

            if ($scope !== null && $scope->project !== null && $scope->environment !== null) {
                return redirect()->route('project.overview', [
                    'organization' => $scope->organization->slug,
                    'project' => $scope->project->key,
                    'environment' => $scope->environment->key,
                ]);
            }
        }

        return redirect()->route('account.no-access');
    }

    /** GET /{organization} */
    public function organization(Request $request): RedirectResponse
    {
        $scope = ResolvePortalScope::scopeOf($request);
        $person = $this->currentPerson->find();

        if ($scope === null || $person === null) {
            return redirect()->route('home');
        }

        $remembered = $person->lastOrganizationId === $scope->organization->id;
        $landing = $this->resolver->landing($person->id, $scope->organization->id, $remembered ? $person->lastProjectId : null, $remembered ? $person->lastEnvironmentId : null);

        if ($landing === null || $landing->project === null || $landing->environment === null) {
            return redirect()->route('account.no-access');
        }

        return redirect()->route('project.overview', [
            'organization' => $landing->organization->slug,
            'project' => $landing->project->key,
            'environment' => $landing->environment->key,
        ]);
    }

    /** GET /{organization}/{project} */
    public function project(Request $request): RedirectResponse
    {
        $scope = ResolvePortalScope::scopeOf($request);
        $person = $this->currentPerson->find();

        if ($scope === null || $scope->project === null || $person === null) {
            return redirect()->route('home');
        }

        $landing = $this->resolver->landing($person->id, $scope->organization->id, $scope->project->id, $person->lastProjectId === $scope->project->id ? $person->lastEnvironmentId : null);

        if ($landing === null || $landing->environment === null) {
            return redirect()->route('account.no-access');
        }

        return redirect()->route('project.overview', [
            'organization' => $scope->organization->slug,
            'project' => $scope->project->key,
            'environment' => $landing->environment->key,
        ]);
    }
}
