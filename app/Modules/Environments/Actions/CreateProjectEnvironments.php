<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Environments\Data\EnvironmentDefinition;
use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Domain\EnvironmentTemplate;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;

/**
 * Creates the environments of a project that is being created, each with version 1 of its protection policy. Production
 * (or prod) starts protected: publishing needs a reason and is limited to Owners and Publishers. It does not authorize on
 * its own: only `CreateProject` calls it, inside the transaction that has already checked `projects.create`.
 */
final class CreateProjectEnvironments
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  list<EnvironmentDefinition>  $definitions
     * @return array<string, string> environment key => id
     */
    public function handle(Actor $actor, string $organizationId, string $projectId, array $definitions): array
    {
        $ids = [];

        foreach ($definitions as $position => $definition) {
            $environment = Environment::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'name' => $definition->name,
                'key' => $definition->key,
                'color' => EnvironmentColor::from($definition->color),
                'position' => $position + 1,
                'status' => EnvironmentStatus::Active,
                'allowed_origins' => [],
                'created_by' => $actor->id(),
            ]);

            $protected = EnvironmentTemplate::startsProtected($definition->key);
            $policy = EnvironmentPolicy::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'environment_id' => $environment->id,
                'version' => 1,
                'is_protected' => $protected,
                'require_reason' => $protected,
                'required_approvals' => 0,
                'allow_self_approval' => false,
                'created_by' => $actor->id(),
            ]);

            $environment->forceFill(['current_policy_id' => $policy->id])->save();

            $this->audit->record($actor, 'environment.created', new AuditTarget('environment', $environment->id, $environment->name), ['key' => $environment->key, 'protected' => $protected], projectId: $projectId, environmentId: $environment->id);
            $this->outbox->push(OutboxMessage::forOrganization($organizationId, 'internal.environment.created', 'environment', $environment->id, ['environment_id' => $environment->id], projectId: $projectId, environmentId: $environment->id));

            $ids[$environment->key] = $environment->id;
        }

        return $ids;
    }
}
