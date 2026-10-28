<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;

/**
 * Makes the person who just created a project its Owner. Only someone who may create projects in the organization can
 * be made an Owner this way, and only of a project they are creating.
 */
final class GrantProjectOwnership
{
    /**
     * @throws AuthorizationDenied
     */
    public function handle(Actor $actor, string $organizationId, string $projectId): void
    {
        $userId = $actor->id();

        if ($userId === null || ! $actor->can(Capability::ProjectsCreate, new OrganizationScope($organizationId))) {
            throw new AuthorizationDenied;
        }

        ProjectMembership::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'user_id' => $userId,
            'role' => ProjectRole::Owner,
            'added_by' => $userId,
        ]);
    }
}
