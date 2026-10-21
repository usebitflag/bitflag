<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Environments\Data\EnvironmentSummary;
use App\Modules\Environments\Queries\EnvironmentLookup;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Organizations\Queries\MembershipLookup;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Navigation\PortalNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page. They carry identifiers, names and booleans only, never models. The TypeScript shape
     * is resources/js/types/shared.ts. Every value is lazy, because the portal scope is resolved by route middleware that
     * runs after this one.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn (): array => ['user' => $this->user()],
            'scope' => fn (): array => $this->scope($request),
            'environments' => fn (): array => $this->environments($request),
            'navigation' => fn (): array => $this->navigation($request),
            'can' => fn (): object|array => $this->can($request),
            'flash' => fn (): array => ['toast' => $this->toast($request)],
            'requestId' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
            // Loaded only when the switcher opens, with a partial reload.
            'switcher' => Inertia::optional(fn (): array => $this->switcher()),
        ];
    }

    /**
     * @return array{id: string, name: string, email: string, initials: string}|null
     */
    private function user(): ?array
    {
        $person = app(CurrentPerson::class)->find();

        return $person === null ? null : [
            'id' => PublicId::encode(IdPrefix::User, $person->id),
            'name' => $person->name,
            'email' => $person->email,
            'initials' => $person->initials(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);

        return [
            'organization' => $scope === null ? null : [
                'id' => PublicId::encode(IdPrefix::Organization, $scope->organization->id),
                'slug' => $scope->organization->slug,
                'name' => $scope->organization->name,
            ],
            'project' => $scope?->project === null ? null : [
                'id' => PublicId::encode(IdPrefix::Project, $scope->project->id),
                'key' => $scope->project->key,
                'name' => $scope->project->name,
            ],
            'environment' => $scope?->environment === null ? null : $this->environment($scope->environment),
            'projectRole' => $scope?->projectRole?->value,
            'orgRole' => $scope?->orgRole->value,
        ];
    }

    /**
     * @return array{id: string, key: string, name: string, color: string, revision: int|null}
     */
    private function environment(EnvironmentSummary $environment): array
    {
        return [
            'id' => PublicId::encode(IdPrefix::Environment, $environment->id),
            'key' => $environment->key,
            'name' => $environment->name,
            'color' => $environment->color,
            'revision' => $environment->revision,
        ];
    }

    /**
     * @return list<array{id: string, key: string, name: string, color: string, revision: int|null}>
     */
    private function environments(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);

        if ($scope === null || $scope->project === null) {
            return [];
        }

        return array_map(
            fn ($environment): array => $this->environment($environment),
            app(EnvironmentLookup::class)->activeFor($scope->organization->id, $scope->project->id),
        );
    }

    /**
     * @return list<array{key: string, label: string, icon: string, group: string, href: string, count: int|null, active: bool}>
     */
    private function navigation(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);

        if ($scope === null || $scope->project === null || $scope->environment === null) {
            return [];
        }

        $parameters = ['organization' => $scope->organization->slug, 'project' => $scope->project->key, 'environment' => $scope->environment->key];

        return array_map(static fn ($entry): array => [
            'key' => $entry->key,
            'label' => $entry->label,
            'icon' => $entry->icon,
            'group' => $entry->group->label(),
            'href' => route($entry->routeName, $parameters, false),
            'count' => $entry->count === null ? null : ($entry->count)(),
            'active' => $request->routeIs($entry->routeName),
        ], app(PortalNavigation::class)->entries());
    }

    /**
     * What the person may do here, for every capability. The server rechecks each action.
     *
     * @return array<string, bool>|object an empty object, not a list, when there is no scope
     */
    private function can(Request $request): array|object
    {
        $scope = ResolvePortalScope::scopeOf($request);
        $actor = $request->attributes->get(ResolveActor::ATTRIBUTE);

        if ($scope === null || ! $actor instanceof Actor) {
            return (object) [];
        }

        $organization = new OrganizationScope($scope->organization->id);
        $project = $scope->project === null ? null : new ProjectScope($scope->organization->id, $scope->project->id);
        $environment = $scope->project === null || $scope->environment === null ? null : new EnvironmentScope($scope->organization->id, $scope->project->id, $scope->environment->id);
        $map = [];

        foreach (Capability::cases() as $capability) {
            $target = $capability->isOrganizationLevel() ? $organization : ($environment ?? $project);
            $map[$capability->value] = $target !== null && $actor->can($capability, $target);
        }

        return $map;
    }

    /**
     * @return array{message: string, tone: string}|null
     */
    private function toast(Request $request): ?array
    {
        $toast = $request->hasSession() ? $request->session()->get('toast') : null;

        if (! is_array($toast) || ! is_string($toast['message'] ?? null)) {
            return null;
        }

        return ['message' => $toast['message'], 'tone' => ($toast['tone'] ?? 'neutral') === 'accent' ? 'accent' : 'neutral'];
    }

    /**
     * The organizations the person belongs to with the projects they can open, for the switcher.
     *
     * @return array{organizations: list<array{id: string, slug: string, name: string, projects: list<array{id: string, key: string, name: string}>}>}
     */
    private function switcher(): array
    {
        $person = app(CurrentPerson::class)->find();

        if ($person === null) {
            return ['organizations' => []];
        }

        $memberships = app(MembershipLookup::class);
        $projects = app(ProjectLookup::class);
        $organizations = [];

        foreach ($memberships->organizationsFor($person->id) as $access) {
            $organizations[] = [
                'id' => PublicId::encode(IdPrefix::Organization, $access->organization->id),
                'slug' => $access->organization->slug,
                'name' => $access->organization->name,
                'projects' => array_map(static fn ($project): array => [
                    'id' => PublicId::encode(IdPrefix::Project, $project->id),
                    'key' => $project->key,
                    'name' => $project->name,
                ], $projects->activeAmong($access->organization->id, $memberships->projectIdsFor($person->id, $access->organization->id))),
            ];
        }

        return ['organizations' => $organizations];
    }
}
