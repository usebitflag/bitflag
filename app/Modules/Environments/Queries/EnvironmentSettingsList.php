<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use Illuminate\Support\Collection;

/**
 * A project's environments for the settings pages: the table of every environment (archived ones last) and the detail of one.
 * Runs inside the organization's tenant context.
 */
/**
 * @phpstan-type EnvironmentRow array{id: string, name: string, key: string, color: string, position: int, status: string, isProtected: bool, requiredApprovals: int, policyVersion: int, firstPublishedAt: int|null, allowedOrigins: list<string>, defaultMaxSnapshotAgeSeconds: int|null, version: int}
 */
final class EnvironmentSettingsList
{
    /**
     * @return list<EnvironmentRow>
     */
    public function for(string $projectId): array
    {
        $environments = Environment::query()->where('project_id', $projectId)->orderBy('position')->orderBy('key')->get();
        $policies = $this->policies($environments);

        return array_values($environments->map(fn (Environment $environment): array => $this->row($environment, $policies))->all());
    }

    /**
     * How many environments the project has, archived ones included.
     */
    public function count(string $projectId): int
    {
        return Environment::query()->where('project_id', $projectId)->count();
    }

    /**
     * The keys of the project's active environments.
     *
     * @return list<string>
     */
    public function activeKeysFor(string $projectId): array
    {
        return array_values(Environment::query()->where('project_id', $projectId)->where('status', 'active')->get()->map(static fn (Environment $environment): string => $environment->key)->all());
    }

    /**
     * @return EnvironmentRow|null
     */
    public function byKey(string $projectId, string $key): ?array
    {
        $environment = Environment::query()->where('project_id', $projectId)->where('key', $key)->first();

        return $environment === null ? null : $this->row($environment, $this->policies(collect([$environment])));
    }

    /**
     * @param  Collection<int, Environment>  $environments
     * @return Collection<string, EnvironmentPolicy>
     */
    private function policies(Collection $environments): Collection
    {
        $ids = $environments->pluck('current_policy_id')->filter()->all();

        return EnvironmentPolicy::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  Collection<string, EnvironmentPolicy>  $policies
     * @return EnvironmentRow
     */
    private function row(Environment $environment, Collection $policies): array
    {
        $policy = $environment->current_policy_id === null ? null : $policies->get($environment->current_policy_id);

        return [
            'id' => $environment->id,
            'name' => $environment->name,
            'key' => $environment->key,
            'color' => $environment->color->value,
            'position' => $environment->position,
            'status' => $environment->status->value,
            'isProtected' => $policy->is_protected ?? false,
            'requiredApprovals' => $policy->required_approvals ?? 0,
            'policyVersion' => $policy->version ?? 1,
            'firstPublishedAt' => $environment->first_published_at?->getTimestamp(),
            'allowedOrigins' => $environment->allowed_origins,
            'defaultMaxSnapshotAgeSeconds' => $environment->default_max_snapshot_age_seconds,
            'version' => $environment->version,
        ];
    }
}
