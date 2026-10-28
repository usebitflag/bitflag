<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers\Web;

use App\Http\Middleware\ResolveActor;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Organizations\Actions\ChooseOrganization;
use App\Modules\Organizations\Data\ChooseOrganizationData;
use App\Modules\Organizations\Queries\MembershipLookup;
use App\Modules\Organizations\Queries\ProjectCounts;
use App\Shared\Actors\Actor;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Choose organization": shown after signing in to a person who belongs to more than one, unless they asked to skip it.
 */
final class ChooseOrganizationController
{
    public function show(CurrentPerson $current, MembershipLookup $memberships, ProjectCounts $counts): Response|RedirectResponse
    {
        $person = $current->find();
        abort_if($person === null, 403);

        $organizations = array_map(static fn ($access): array => [
            'id' => PublicId::encode(IdPrefix::Organization, $access->organization->id),
            'slug' => $access->organization->slug,
            'name' => $access->organization->name,
            'role' => $access->role->value,
            'projectCount' => 0,
        ], array_filter($memberships->organizationsFor($person->id), static fn ($access): bool => $access->organization->active));

        foreach ($organizations as $index => $organization) {
            $organizations[$index]['projectCount'] = $counts->for($person->id, PublicId::decode(IdPrefix::Organization, $organization['id']));
        }

        if ($organizations === []) {
            return redirect('/');
        }

        return Inertia::render('auth/ChooseOrganization', [
            'organizations' => array_values($organizations),
            'remember' => $person->rememberOrgChoice,
        ]);
    }

    public function store(Request $request, ChooseOrganization $choose, MembershipLookup $memberships, CurrentPerson $current): RedirectResponse
    {
        $request->validate(['organization' => ['required', 'string', 'max:40'], 'remember' => ['sometimes', 'boolean']]);

        $actor = $request->attributes->get(ResolveActor::ATTRIBUTE);
        $person = $current->find();
        abort_if(! $actor instanceof Actor || $person === null, 403);

        $access = $memberships->organizationBySlug($person->id, $request->string('organization')->toString());
        abort_if($access === null || ! $access->organization->active, 404);

        $choose->handle($actor, new ChooseOrganizationData($access->organization->id, $request->boolean('remember')));

        return redirect()->route('organization.home', ['organization' => $access->organization->slug]);
    }
}
