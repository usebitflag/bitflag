<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Environments\Data\EnvironmentDeliverySettingsData;
use App\Modules\Environments\Models\Environment;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Contracts\Cache\Repository;

/** Reads the small environment policy SDK requests need, cached until its outbox invalidation arrives. */
final class EnvironmentDeliverySettings
{
    private const TTL_SECONDS = 300;

    public function __construct(private readonly Repository $cache) {}

    public function find(string $organizationId, string $projectId, string $environmentId): ?EnvironmentDeliverySettingsData
    {
        return $this->cache->remember(self::cacheKey($environmentId), self::TTL_SECONDS, fn (): ?EnvironmentDeliverySettingsData => Tenancy::crossTenant(
            'SDK delivery resolves its credential-bound environment',
            function () use ($organizationId, $projectId, $environmentId): ?EnvironmentDeliverySettingsData {
                $environment = Environment::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->find($environmentId);

                return $environment === null ? null : new EnvironmentDeliverySettingsData(
                    $environment->id,
                    $environment->allowed_origins,
                    $environment->default_max_snapshot_age_seconds,
                    $environment->status,
                );
            },
        ));
    }

    public static function cacheKey(string $environmentId): string
    {
        return "environment-delivery-settings:{$environmentId}";
    }
}
