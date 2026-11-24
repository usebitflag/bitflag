<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Controllers\Api\V1;

use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Data\ResolvedCredential;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Http\Middleware\AuthenticateManagementToken;
use App\Modules\Identity\Queries\PersonLookup;
use App\Shared\Actors\Actor;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Who the presented token is, and a way to end it (the CLI's logout).
 */
final class MeController
{
    /** GET /api/v1/me */
    public function show(Request $request, PersonLookup $people): JsonResponse
    {
        $credential = $this->credential($request);
        $user = $credential->userId === null ? null : $people->profiles([$credential->userId])[$credential->userId] ?? null;

        return response()->json([
            'type' => $credential->type->value,
            'id' => PublicId::encode(IdPrefix::Key, $credential->id),
            'label' => $credential->label,
            'organization' => PublicId::encode(IdPrefix::Organization, $credential->organizationId),
            'project' => PublicId::encode(IdPrefix::Project, $credential->projectId),
            'environment' => $credential->environmentId === null ? null : PublicId::encode(IdPrefix::Environment, $credential->environmentId),
            'scopes' => $credential->scopes,
            'expires_at' => $credential->expiresAtMs === null ? null : gmdate('Y-m-d\TH:i:s\Z', intdiv($credential->expiresAtMs, 1000)),
            'user' => $user === null ? null : ['id' => PublicId::encode(IdPrefix::User, (string) $credential->userId), 'name' => $user['name']],
        ]);
    }

    /** DELETE /api/v1/me/token */
    public function destroy(Request $request, Actor $actor, RevokeCredential $revoke): Response
    {
        $credential = $this->credential($request);

        // The token ends itself: the system acts for it, because a token may not revoke through its own scopes.
        $revoke->handle(Actor::system('token logout'), new RevokeCredentialData($credential->organizationId, $credential->id));

        return response()->noContent();
    }

    private function credential(Request $request): ResolvedCredential
    {
        $credential = $request->attributes->get(AuthenticateManagementToken::CREDENTIAL);
        abort_unless($credential instanceof ResolvedCredential, 401);

        return $credential;
    }
}
