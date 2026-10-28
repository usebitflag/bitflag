<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Credentials\Actions\IssueCredential;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Actions\RotateCredential;
use App\Modules\Credentials\Data\IssueCredentialData;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Data\RotateCredentialData;
use App\Modules\Credentials\Http\Requests\IssueCredentialRequest;
use App\Modules\Credentials\Queries\EnvironmentCredentials;
use App\Modules\Environments\Queries\EnvironmentLookup;
use App\Shared\Actors\Actor;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creating, rotating and revoking an environment's keys. A new secret is returned once, as JSON, in the response to the
 * request that makes it: it is never put in the session, flash data or page props, and the response is not cacheable.
 */
final class EnvironmentKeysController
{
    /** POST .../settings/environments/{target}/keys */
    public function store(IssueCredentialRequest $request, Actor $actor, IssueCredential $issue, EnvironmentCredentials $credentials, EnvironmentLookup $environments): JsonResponse
    {
        [$organizationId, $projectId, $environmentId] = $this->target($request, $environments);
        $ttl = $request->input('ttl_days');

        $issued = $issue->handle($actor, new IssueCredentialData(
            $organizationId,
            $projectId,
            $environmentId,
            $request->string('type')->toString(),
            $request->string('label')->toString(),
            array_values(array_filter($request->array('scopes'), 'is_string')),
            $ttl === null || $ttl === '' ? null : $request->integer('ttl_days'),
        ));

        return $this->secretResponse($credentials, $environmentId, $issued->credentialId, $issued->secret->reveal());
    }

    /** POST .../settings/environments/{target}/keys/{credential}/rotate */
    public function rotate(Request $request, Actor $actor, RotateCredential $rotate, EnvironmentCredentials $credentials, EnvironmentLookup $environments): JsonResponse
    {
        [$organizationId, $projectId, $environmentId] = $this->target($request, $environments);
        $request->validate(['overlap' => ['required', 'integer']]);

        $rotated = $rotate->handle($actor, new RotateCredentialData($organizationId, $projectId, $environmentId, $this->credentialId($request), $request->integer('overlap')));

        return $this->secretResponse($credentials, $environmentId, $rotated->replacementId, $rotated->secret->reveal(), ['oldStopsAt' => $rotated->oldStopsAt->getTimestamp()]);
    }

    /** POST .../settings/environments/{target}/keys/{credential}/revoke */
    public function revoke(Request $request, Actor $actor, RevokeCredential $revoke, EnvironmentLookup $environments, EnvironmentCredentials $credentials): RedirectResponse
    {
        [$organizationId, , $environmentId] = $this->target($request, $environments);

        // The credential must belong to the environment in the address, or it is not found.
        $id = $this->credentialId($request);
        abort_unless($credentials->inEnvironment($environmentId, $id), 404);

        $revoke->handle($actor, new RevokeCredentialData($organizationId, $id));

        return back()->with('toast', ['message' => 'Key revoked.', 'tone' => 'neutral']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function secretResponse(EnvironmentCredentials $credentials, string $environmentId, string $credentialId, string $secret, array $extra = []): JsonResponse
    {
        $row = collect($credentials->for($environmentId))->firstWhere('id', PublicId::encode(IdPrefix::Key, $credentialId));

        return response()->json(['credential' => $row, 'secret' => $secret, ...$extra], 201, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function target(Request $request, EnvironmentLookup $environments): array
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null || $scope->project === null, 404);
        $key = $request->route('target');
        abort_unless(is_string($key), 404);

        $environment = $environments->byKey($scope->organization->id, $scope->project->id, $key);
        abort_if($environment === null, 404);

        return [$scope->organization->id, $scope->project->id, $environment->id];
    }

    private function credentialId(Request $request): string
    {
        $value = $request->route('credential');
        abort_unless(is_string($value), 404);

        return PublicId::decode(IdPrefix::Key, $value);
    }
}
