<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Controllers\Web;

use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Queries\PersonalTokens;
use App\Modules\Organizations\Queries\MembershipLookup;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Actors\Actor;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A person's own personal tokens across every project, with revoke. Tokens are made on a project's API tokens page.
 */
final class AccountTokensController
{
    /** GET /account/tokens */
    public function index(Actor $actor, PersonalTokens $tokens, MembershipLookup $memberships, ProjectLookup $projects): Response
    {
        $userId = (string) $actor->id();
        $organizations = [];

        foreach ($memberships->organizationsFor($userId) as $access) {
            $organizations[$access->organization->id] = $access->organization;
        }

        $rows = [];

        foreach ($tokens->forUserEverywhere($userId) as $token) {
            $organization = $organizations[$token['organizationId']] ?? null;
            $project = $projects->byId($token['organizationId'], $token['projectId']);
            unset($token['organizationId'], $token['projectId']);

            $rows[] = [...$token, 'project' => $project === null ? null : ['key' => $project->key, 'name' => $project->name], 'organization' => $organization === null ? null : ['slug' => $organization->slug, 'name' => $organization->name]];
        }

        return Inertia::render('account/Tokens', ['tokens' => $rows]);
    }

    /** POST /account/tokens/{credential}/revoke */
    public function revoke(Request $request, Actor $actor, PersonalTokens $tokens, RevokeCredential $revoke): RedirectResponse
    {
        $value = $request->route('credential');
        abort_unless(is_string($value), 404);
        $id = PublicId::decode(IdPrefix::Key, $value);

        $organizationId = $tokens->ownedBy((string) $actor->id(), $id);
        abort_if($organizationId === null, 404);

        $revoke->handle($actor, new RevokeCredentialData($organizationId, $id));

        return back()->with('toast', ['message' => 'Token revoked.', 'tone' => 'neutral']);
    }
}
