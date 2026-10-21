<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Environments\Queries\EnvironmentLookup;
use App\Modules\Identity\Data\RememberLastScopeData;
use App\Modules\Identity\Models\User;
use App\Shared\Actors\Actor;
use App\Shared\Actors\ActorType;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;

/**
 * Remembers the project and environment a person last worked in, so the portal opens there next time. It is a
 * preference, not a change to customer data, so it is not audited. The person must be able to read the project.
 */
final class RememberLastScope
{
    public function __construct(private readonly EnvironmentLookup $environments) {}

    public function handle(Actor $actor, RememberLastScopeData $data): void
    {
        $userId = $actor->id();

        if ($actor->type() !== ActorType::User || $userId === null) {
            throw new AuthorizationDenied;
        }

        if (! $actor->can(Capability::ProjectRead, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        if ($this->environments->byId($data->organizationId, $data->projectId, $data->environmentId) === null) {
            throw new ResourceNotFound;
        }

        User::query()->whereKey($userId)->update([
            'last_organization_id' => $data->organizationId,
            'last_project_id' => $data->projectId,
            'last_environment_id' => $data->environmentId,
        ]);
    }
}
