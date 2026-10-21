<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Portal\PortalScope;
use App\Http\Portal\PortalScopeResolver;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the organization, project and environment in a portal URL into a verified scope for the signed-in person. If
 * the person is not a member, or the project or environment is archived, the answer is 404, so the existence of
 * someone else's project is never disclosed. On success it sets the tenant context and keeps the scope on the request.
 */
final class ResolvePortalScope
{
    public const ATTRIBUTE = 'portal_scope';

    public function __construct(
        private readonly PortalScopeResolver $resolver,
        private readonly CurrentPerson $currentPerson,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $person = $this->currentPerson->find();
        $route = $request->route();

        if ($person === null || $route === null) {
            throw new ResourceNotFound;
        }

        $segment = fn (string $name): ?string => is_string($route->parameter($name)) ? $route->parameter($name) : null;
        $organization = $segment('organization');

        if ($organization === null) {
            throw new ResourceNotFound;
        }

        $scope = $this->resolver->resolve($person->id, $organization, $segment('project'), $segment('environment'));

        if ($scope === null) {
            throw new ResourceNotFound;
        }

        $this->tenant->set($scope->organization->id);
        $request->attributes->set(self::ATTRIBUTE, $scope);

        return $next($request);
    }

    public static function scopeOf(Request $request): ?PortalScope
    {
        $scope = $request->attributes->get(self::ATTRIBUTE);

        return $scope instanceof PortalScope ? $scope : null;
    }
}
