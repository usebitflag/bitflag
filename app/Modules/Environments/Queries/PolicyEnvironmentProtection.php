<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Shared\Capabilities\EnvironmentProtection;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Tenancy\TenantContext;

/**
 * An environment is protected when the current version of its policy says so. Protected environments can be published
 * only by roles that hold `environment.publish.protected` (Owners and Publishers). An environment with no policy is not
 * protected.
 */
final class PolicyEnvironmentProtection implements EnvironmentProtection
{
    /** @var array<string, bool> */
    private array $known = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function isProtected(EnvironmentScope $environment): bool
    {
        return $this->known[$environment->environmentId] ??= $this->tenant->run($environment->organizationId(), function () use ($environment): bool {
            $policy = EnvironmentPolicy::query()
                ->where('environment_id', $environment->environmentId)
                ->orderByDesc('version')
                ->first();

            return $policy !== null && $policy->is_protected;
        });
    }
}
