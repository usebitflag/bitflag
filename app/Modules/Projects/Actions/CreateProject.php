<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Environments\Actions\CreateProjectEnvironments;
use App\Modules\Environments\Data\EnvironmentDefinition;
use App\Modules\Environments\Queries\EnvironmentTemplates;
use App\Modules\Organizations\Actions\GrantProjectOwnership;
use App\Modules\Organizations\Queries\OrganizationStatuses;
use App\Modules\Projects\Data\CreateProjectData;
use App\Modules\Projects\Data\CreateProjectResult;
use App\Modules\Projects\Domain\ProjectKeyRules;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Plans\EnforcePlanLimit;
use App\Shared\Plans\LimitKey;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a project with its environments, the first version of each environment's protection policy, and the creator as
 * its Owner. Without a list of environments it starts from the template (development, staging, production), and production
 * starts protected. Needs `projects.create` and room under the plan's limits.
 */
final class CreateProject
{
    public function __construct(
        private readonly EnforcePlanLimit $limits,
        private readonly EnvironmentTemplates $templates,
        private readonly CreateProjectEnvironments $createEnvironments,
        private readonly GrantProjectOwnership $grantOwnership,
        private readonly OrganizationStatuses $organizations,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, CreateProjectData $data): CreateProjectResult
    {
        $userId = $actor->id();

        if ($userId === null || ! $actor->can(Capability::ProjectsCreate, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        $name = trim($data->name);
        $key = strtolower(trim($data->key));
        $environments = $this->templates->normalize($data->environments);

        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Enter a name of up to 80 characters.']);
        }

        $problem = ProjectKeyRules::problem($key);

        if ($problem !== null) {
            throw ValidationException::withMessages(['key' => $problem]);
        }

        if (mb_strlen((string) $data->description) > 280) {
            throw ValidationException::withMessages(['description' => 'Use up to 280 characters.']);
        }

        if (! $this->organizations->isActive($data->organizationId)) {
            throw new AuthorizationDenied('This organization is not active.');
        }

        $this->limits->handle($data->organizationId, LimitKey::Projects);
        $this->limits->handle($data->organizationId, LimitKey::EnvironmentsPerProject, null, count($environments));

        try {
            return $this->tenant->run($data->organizationId, fn (): CreateProjectResult => DB::transaction(
                fn (): CreateProjectResult => $this->create($actor, $userId, $data, $name, $key, $environments),
            ));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => ProjectKeyRules::TAKEN]);
        }
    }

    /**
     * @param  list<EnvironmentDefinition>  $environments
     */
    private function create(Actor $actor, string $userId, CreateProjectData $data, string $name, string $key, array $environments): CreateProjectResult
    {
        $project = Project::query()->create([
            'organization_id' => $data->organizationId,
            'name' => $name,
            'key' => $key,
            'description' => $data->description === null || trim($data->description) === '' ? null : trim($data->description),
            'status' => ProjectStatus::Active,
        ]);

        $this->grantOwnership->handle($actor, $data->organizationId, $project->id);

        $this->audit->record($actor, 'project.created', new AuditTarget('project', $project->id, $project->name), ['key' => $project->key], projectId: $project->id);
        $this->outbox->push(OutboxMessage::forOrganization($data->organizationId, 'internal.project.created', 'project', $project->id, ['project_id' => $project->id], projectId: $project->id));

        $ids = $this->createEnvironments->handle($actor, $data->organizationId, $project->id, $environments);

        return new CreateProjectResult($project->id, $project->key, $ids);
    }
}
