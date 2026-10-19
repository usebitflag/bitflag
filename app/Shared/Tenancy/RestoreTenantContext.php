<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Closure;

/**
 * Job middleware. Sets the tenant context from a TenantAware job and always clears it afterwards, including when the
 * job throws, so the next job on the same worker starts with no tenant.
 */
final class RestoreTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $context = app(TenantContext::class);
        $organizationId = $job instanceof TenantAware ? $job->organizationId() : null;

        $context->clear();

        if ($organizationId !== null) {
            $context->set($organizationId);
        }

        try {
            return $next($job);
        } finally {
            $context->clear();
        }
    }
}
