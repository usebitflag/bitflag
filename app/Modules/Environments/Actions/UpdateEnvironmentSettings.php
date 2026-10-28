<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Environments\Data\UpdateEnvironmentSettingsData;
use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentKeyRules;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Domain\OriginRules;
use App\Modules\Environments\Domain\PolicyChange;
use App\Modules\Environments\Events\EnvironmentPolicyUpdated;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Projects\Queries\ProjectWritability;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionedUpdate;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Plans\Entitlements;
use App\Shared\Plans\Feature;
use App\Shared\Plans\PlanRestrictionGate;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves an environment's settings: name, key, label color, browser origins, the default maximum snapshot age and protection.
 * A change to protection or required approvals inserts the next policy version and moves the environment to it; policy rows
 * are never updated. Raising required approvals needs a plan that includes them. The key can change only until the first
 * publication. Returns the new version.
 */
final class UpdateEnvironmentSettings
{
    /** The maximum snapshot ages the form offers, in seconds. Null (no limit) is also allowed. */
    public const SNAPSHOT_AGES = [300, 3600, 86400];

    public function __construct(
        private readonly PlanRestrictionGate $restrictions,
        private readonly ProjectWritability $writability,
        private readonly Entitlements $entitlements,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, UpdateEnvironmentSettingsData $data): int
    {
        if (! $actor->can(Capability::EnvironmentManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $name = trim($data->name);
        $key = strtolower(trim($data->key));
        $color = EnvironmentColor::tryFrom($data->color);
        $originProblem = OriginRules::firstProblem($data->allowedOrigins);

        $problems = array_filter([
            'name' => EnvironmentKeyRules::name($name),
            'key' => EnvironmentKeyRules::problem($key),
            'color' => $color === null ? 'Choose one of the colors.' : null,
            'max_snapshot_age' => $data->defaultMaxSnapshotAgeSeconds === null || in_array($data->defaultMaxSnapshotAgeSeconds, self::SNAPSHOT_AGES, true) ? null : 'Choose none, 5 minutes, 1 hour or 24 hours.',
            'allowed_origins' => $originProblem[1] ?? null,
        ]);

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $problems));
        }

        $normalizedOrigins = OriginRules::normalizeAll($data->allowedOrigins);
        $policy = PolicyChange::from($data->isProtected, $data->requiredApprovals);

        try {
            return $this->tenant->run($data->organizationId, fn (): int => DB::transaction(
                fn (): int => $this->save($actor, $data, $name, $key, $color ?? EnvironmentColor::Slate, $normalizedOrigins, $policy),
            ));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => EnvironmentKeyRules::TAKEN]);
        }
    }

    /**
     * @param  list<string>  $origins
     */
    private function save(Actor $actor, UpdateEnvironmentSettingsData $data, string $name, string $key, EnvironmentColor $color, array $origins, PolicyChange $policy): int
    {
        $this->restrictions->assertConfigWritable($data->organizationId, $data->projectId, $data->environmentId);
        $this->writability->assertWritable($data->projectId);

        $environment = Environment::query()->where('project_id', $data->projectId)->lockForUpdate()->findOrFail($data->environmentId);

        if ($environment->status !== EnvironmentStatus::Active) {
            throw ValidationException::withMessages(['environment' => 'An archived environment cannot be changed.']);
        }

        if ($key !== $environment->key && $environment->first_published_at !== null) {
            throw ValidationException::withMessages(['key' => EnvironmentKeyRules::LOCKED]);
        }

        $current = EnvironmentPolicy::query()->findOrFail($environment->current_policy_id);
        $policyChanged = $policy->differsFrom($current->is_protected, $current->required_approvals);

        if ($policyChanged && $policy->raisesApprovalsAbove($current->required_approvals)) {
            $this->entitlements->assertAllows($data->organizationId, Feature::RequiredApprovals);
        }

        $after = [
            'name' => $name,
            'key' => $key,
            'color' => $color,
            'allowed_origins' => $origins,
            'default_max_snapshot_age_seconds' => $data->defaultMaxSnapshotAgeSeconds,
        ];
        $before = [
            'name' => $environment->name,
            'key' => $environment->key,
            'color' => $environment->color,
            'allowed_origins' => $environment->allowed_origins,
            'default_max_snapshot_age_seconds' => $environment->default_max_snapshot_age_seconds,
        ];
        $changed = array_keys(array_filter($after, static fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

        $version = VersionedUpdate::apply(
            Environment::query()->whereKey($environment->id),
            $data->expectedVersion,
            [...$after, 'allowed_origins' => json_encode($origins, JSON_THROW_ON_ERROR), 'color' => $color->value],
        );

        $policyVersion = $current->version;

        if ($policyChanged) {
            $next = EnvironmentPolicy::query()->create([
                'organization_id' => $data->organizationId,
                'project_id' => $data->projectId,
                'environment_id' => $environment->id,
                'version' => $current->version + 1,
                'is_protected' => $policy->isProtected,
                'require_reason' => $policy->requireReason(),
                'required_approvals' => $policy->requiredApprovals,
                'allow_self_approval' => $current->allow_self_approval,
                'approval_ttl_hours' => $current->approval_ttl_hours,
                'created_by' => $actor->id(),
            ]);
            Environment::query()->whereKey($environment->id)->update(['current_policy_id' => $next->id]);
            $policyVersion = $next->version;
        }

        $target = new AuditTarget('environment', $environment->id, $name);
        $plain = static fn (array $values): array => array_map(static fn (mixed $value): mixed => $value instanceof EnvironmentColor ? $value->value : $value, $values);

        if ($changed !== []) {
            $this->audit->record($actor, 'environment.updated', $target, [
                'changed' => $changed,
                'before' => $plain(array_intersect_key($before, array_flip($changed))),
                'after' => $plain(array_intersect_key($after, array_flip($changed))),
                'version' => $version,
            ], projectId: $data->projectId, environmentId: $environment->id);
        }

        if ($policyChanged) {
            $this->audit->record($actor, 'environment.policy_changed', $target, [
                'before' => ['is_protected' => $current->is_protected, 'required_approvals' => $current->required_approvals, 'policy_version' => $current->version],
                'after' => ['is_protected' => $policy->isProtected, 'required_approvals' => $policy->requiredApprovals, 'policy_version' => $policyVersion],
            ], projectId: $data->projectId, environmentId: $environment->id);
        }

        if (array_intersect(['allowed_origins', 'default_max_snapshot_age_seconds'], $changed) !== []) {
            $this->outbox->push(OutboxMessage::forOrganization($data->organizationId, 'internal.environment.delivery_settings_changed', 'environment', $environment->id, ['environment_id' => $environment->id], projectId: $data->projectId, environmentId: $environment->id));
        }

        if ($policyChanged) {
            DB::afterCommit(static fn () => event(new EnvironmentPolicyUpdated($data->organizationId, $data->projectId, $environment->id, $policyVersion)));
        }

        return $version;
    }
}
