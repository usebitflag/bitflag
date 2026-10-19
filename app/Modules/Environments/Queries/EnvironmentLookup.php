<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Environments\Data\EnvironmentSummary;
use App\Modules\Environments\Models\Environment;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;

final class EnvironmentLookup
{
    public function byKey(string $organizationId, string $projectId, string $key): ?EnvironmentSummary
    {
        return $this->first($organizationId, $projectId, fn ($query) => $query->where('key', $key));
    }

    public function byId(string $organizationId, string $projectId, string $environmentId): ?EnvironmentSummary
    {
        return $this->first($organizationId, $projectId, fn ($query) => $query->whereKey($environmentId));
    }

    /**
     * The project's active environments in display order.
     *
     * @return list<EnvironmentSummary>
     */
    public function activeFor(string $organizationId, string $projectId): array
    {
        return Tenancy::crossTenant('environment lookup resolves the portal scope', fn (): array => array_values(Environment::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->orderBy('position')
            ->orderBy('key')
            ->get()
            ->map(fn (Environment $environment): EnvironmentSummary => $this->summary($environment))
            ->all()));
    }

    /**
     * @param  callable(Builder<Environment>): Builder<Environment>  $narrow
     */
    private function first(string $organizationId, string $projectId, callable $narrow): ?EnvironmentSummary
    {
        return Tenancy::crossTenant('environment lookup resolves the portal scope', function () use ($organizationId, $projectId, $narrow): ?EnvironmentSummary {
            $environment = $narrow(Environment::query()->where('organization_id', $organizationId)->where('project_id', $projectId))->first();

            return $environment === null ? null : $this->summary($environment);
        });
    }

    private function summary(Environment $environment): EnvironmentSummary
    {
        return new EnvironmentSummary(
            $environment->id,
            $environment->organization_id,
            $environment->project_id,
            $environment->key,
            $environment->name,
            $environment->color,
            $environment->position,
            $environment->status,
        );
    }
}
