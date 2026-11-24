<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Environments\Data\CreateEnvironmentData;
use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentKeyRules;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Projects\Queries\ProjectWritability;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Plans\EnforcePlanLimit;
use App\Shared\Plans\LimitKey;
use App\Shared\Plans\PlanRestrictionGate;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds an environment to a project, after the one named (or last), with version 1 of its protection policy. A protected
 * environment needs a reason on every publish and limits publishing to Owners and Publishers. Every flag starts disabled
 * there. Returns the new environment's ID.
 */
final class CreateEnvironment
{
    public function __construct(
        private readonly EnforcePlanLimit $limits,
        private readonly PlanRestrictionGate $restrictions,
        private readonly ProjectWritability $writability,
        private readonly RenumberEnvironments $renumber,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, CreateEnvironmentData $data): string
    {
        if (! $actor->can(Capability::EnvironmentManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $name = trim($data->name);
        $key = strtolower(trim($data->key));
        $color = EnvironmentColor::tryFrom($data->color);

        $problems = array_filter([
            'name' => EnvironmentKeyRules::name($name),
            'key' => EnvironmentKeyRules::problem($key),
            'color' => $color === null ? 'Choose one of the colors.' : null,
        ]);

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $problems));
        }

        try {
            return $this->tenant->run($data->organizationId, fn (): string => DB::transaction(
                fn (): string => $this->create($actor, $data, $name, $key, $color ?? EnvironmentColor::Slate),
            ));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => EnvironmentKeyRules::TAKEN]);
        }
    }

    private function create(Actor $actor, CreateEnvironmentData $data, string $name, string $key, EnvironmentColor $color): string
    {
        // The plan checks come first inside the transaction, so concurrent creations at a limit produce one success.
        $this->limits->handle($data->organizationId, LimitKey::EnvironmentsPerProject, $data->projectId);
        $this->restrictions->assertConfigWritable($data->organizationId, $data->projectId);
        $this->writability->assertWritable($data->projectId);

        $active = $this->renumber->activeIds($data->projectId);

        $after = $data->afterEnvironmentId;

        if ($after !== null && ! in_array($after, $active, true)) {
            throw ValidationException::withMessages(['after' => 'Choose an environment from this project.']);
        }

        $environment = Environment::query()->create([
            'organization_id' => $data->organizationId,
            'project_id' => $data->projectId,
            'name' => $name,
            'key' => $key,
            'color' => $color,
            'position' => count($active) + 1,
            'status' => EnvironmentStatus::Active,
            'allowed_origins' => [],
            'created_by' => $actor->id(),
        ]);

        $policy = EnvironmentPolicy::query()->create([
            'organization_id' => $data->organizationId,
            'project_id' => $data->projectId,
            'environment_id' => $environment->id,
            'version' => 1,
            'is_protected' => $data->protected,
            'require_reason' => $data->protected,
            'required_approvals' => 0,
            'allow_self_approval' => false,
            'created_by' => $actor->id(),
        ]);

        $environment->forceFill(['current_policy_id' => $policy->id])->save();

        $ordered = [];

        foreach ($active as $id) {
            $ordered[] = $id;

            if ($id === $after) {
                $ordered[] = $environment->id;
            }
        }

        if ($after === null) {
            $ordered[] = $environment->id;
        }

        $this->renumber->handle($data->projectId, $ordered);

        $this->audit->record($actor, 'environment.created', new AuditTarget('environment', $environment->id, $name), ['key' => $key, 'protected' => $data->protected], projectId: $data->projectId, environmentId: $environment->id);
        $this->outbox->push(OutboxMessage::forOrganization($data->organizationId, 'internal.environment.created', 'environment', $environment->id, ['environment_id' => $environment->id], projectId: $data->projectId, environmentId: $environment->id));

        return $environment->id;
    }
}
