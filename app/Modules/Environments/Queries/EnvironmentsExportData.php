<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Lifecycle\ProjectExportSection;

/**
 * The `environments` section of an export: each environment's settings and its full protection policy history. Credentials are
 * not part of it.
 */
final class EnvironmentsExportData implements ProjectExportSection
{
    public function name(): string
    {
        return 'environments';
    }

    public function export(string $organizationId, string $projectId): array
    {
        $history = [];

        foreach (EnvironmentPolicy::query()->where('project_id', $projectId)->orderBy('version')->get() as $policy) {
            $history[$policy->environment_id][] = [
                'version' => $policy->version,
                'protected' => $policy->is_protected,
                'require_reason' => $policy->require_reason,
                'required_approvals' => $policy->required_approvals,
                'allow_self_approval' => $policy->allow_self_approval,
                'approval_ttl_hours' => $policy->approval_ttl_hours,
                'created_at' => $policy->created_at?->toIso8601String(),
            ];
        }

        $environments = [];

        foreach (Environment::query()->where('project_id', $projectId)->orderBy('position')->get() as $environment) {
            $environments[] = [
                'id' => PublicId::encode(IdPrefix::Environment, $environment->id),
                'key' => $environment->key,
                'name' => $environment->name,
                'color' => $environment->color->value,
                'position' => $environment->position,
                'status' => $environment->status->value,
                'allowed_origins' => $environment->allowed_origins,
                'default_max_snapshot_age_seconds' => $environment->default_max_snapshot_age_seconds,
                'first_published_at' => $environment->first_published_at?->toIso8601String(),
                'policy_history' => $history[$environment->id] ?? [],
            ];
        }

        return $environments;
    }
}
