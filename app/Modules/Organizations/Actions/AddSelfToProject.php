<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lets an Org Owner join any project of the organization as its Owner. Being an Org Owner does not by itself open a project,
 * so this is the deliberate step, and it is audited.
 */
final class AddSelfToProject
{
    public function __construct(
        private readonly ProjectLookup $projects,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ResourceNotFound
     * @throws ValidationException
     */
    public function handle(Actor $actor, string $organizationId, string $projectId): void
    {
        $userId = $actor->id();

        if ($userId === null || ! $actor->can(Capability::OrgManage, new OrganizationScope($organizationId))) {
            throw new AuthorizationDenied;
        }

        $project = $this->projects->byId($organizationId, $projectId);

        if ($project === null || ! $project->active) {
            throw new ResourceNotFound;
        }

        $this->tenant->run($organizationId, function () use ($actor, $userId, $organizationId, $project): void {
            DB::transaction(function () use ($actor, $userId, $organizationId, $project): void {
                if (ProjectMembership::query()->where('project_id', $project->id)->where('user_id', $userId)->exists()) {
                    throw ValidationException::withMessages(['project' => 'You are already a member of this project.']);
                }

                ProjectMembership::query()->create([
                    'organization_id' => $organizationId,
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'role' => ProjectRole::Owner,
                    'added_by' => $userId,
                ]);

                $this->audit->record($actor, 'project_member.self_added', new AuditTarget('project_member', $userId, $actor->displayName()), ['project_id' => $project->id], projectId: $project->id);
            });
        });
    }
}
