<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Credentials\Actions\IssuePersonalAccessToken;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Data\IssuePersonalAccessTokenData;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Http\Requests\IssuePersonalAccessTokenRequest;
use App\Modules\Credentials\Queries\PersonalTokens;
use App\Modules\Environments\Queries\EnvironmentLookup;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's API tokens page: the person's own personal tokens for the project, and the project's management keys, read-only.
 * Making a token returns its secret once as JSON and asks for the password again.
 */
final class ProjectTokensController
{
    /** GET .../settings/tokens */
    public function index(Request $request, Actor $actor, PersonalTokens $tokens, EnvironmentLookup $environments): Response
    {
        [$organizationId, $projectId] = $this->project($request);
        $scope = new ProjectScope($organizationId, $projectId);
        Gate::authorize(Capability::ProjectRead->value, $scope);

        $management = array_map(function (array $key) use ($organizationId, $projectId, $environments): array {
            $environment = $key['environmentId'] === null ? null : $environments->byId($organizationId, $projectId, $key['environmentId']);
            unset($key['environmentId']);

            return [...$key, 'environment' => $environment === null ? null : ['key' => $environment->key, 'name' => $environment->name]];
        }, $tokens->managementKeys($projectId));

        return Inertia::render('project/settings/Tokens', [
            'tokens' => $actor->id() === null ? [] : $tokens->forUserInProject($actor->id(), $projectId),
            'managementKeys' => $management,
            'canDraft' => Gate::allows(Capability::FlagsDraftWrite->value, $scope),
            'canPublish' => Gate::allows(Capability::FlagsDraftWrite->value, $scope) && Gate::allows(Capability::EnvironmentPublish->value, $scope),
        ]);
    }

    /** POST .../settings/tokens */
    public function store(IssuePersonalAccessTokenRequest $request, Actor $actor, IssuePersonalAccessToken $issue, PersonalTokens $tokens): JsonResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $ttl = $request->input('ttl_days');

        $issued = $issue->handle($actor, new IssuePersonalAccessTokenData(
            $organizationId,
            $projectId,
            $request->string('label')->toString(),
            $request->string('access')->toString(),
            $ttl === null || $ttl === '' ? null : $request->integer('ttl_days'),
        ));

        $row = collect($tokens->forUserInProject((string) $actor->id(), $projectId))->firstWhere('id', PublicId::encode(IdPrefix::Key, $issued->credentialId));

        return response()->json(['token' => $row, 'secret' => $issued->secret->reveal()], 201, ['Cache-Control' => 'no-store, private']);
    }

    /** POST .../settings/tokens/{credential}/revoke */
    public function revoke(Request $request, Actor $actor, RevokeCredential $revoke, PersonalTokens $tokens): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $value = $request->route('credential');
        abort_unless(is_string($value), 404);
        $id = PublicId::decode(IdPrefix::Key, $value);

        // Only a token of this project can be revoked from here.
        abort_unless($tokens->inProject($projectId, $id), 404);

        $revoke->handle($actor, new RevokeCredentialData($organizationId, $id));

        return back()->with('toast', ['message' => 'Token revoked.', 'tone' => 'neutral']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null || $scope->project === null, 404);

        return [$scope->organization->id, $scope->project->id];
    }
}
