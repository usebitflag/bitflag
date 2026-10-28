<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Middleware;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Identity\Queries\TwoFactorStatus;
use App\Modules\Organizations\Queries\OrganizationSecurityPolicies;
use App\Shared\Errors\MfaRequired;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a member of an organization that requires two-factor sign-in from working in it until they have set it up. A page
 * request is sent to account security, which says why; a background request that is not Inertia gets 403 MFA_REQUIRED.
 * Account pages are outside the organization, so the person can always reach them. Runs after the portal scope is known.
 */
final class EnsureOrganizationMfa
{
    public function __construct(
        private readonly CurrentPerson $currentPerson,
        private readonly OrganizationSecurityPolicies $policies,
        private readonly TwoFactorStatus $twoFactor,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $scope = ResolvePortalScope::scopeOf($request);
        $person = $this->currentPerson->find();

        if ($scope === null || $person === null || ! $this->policies->requiresMfa($scope->organization->id) || $this->twoFactor->isEnrolled($person->id)) {
            return $next($request);
        }

        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            throw new MfaRequired($scope->organization->name);
        }

        return redirect('/account/security?'.http_build_query(['required_by' => $scope->organization->name]));
    }
}
