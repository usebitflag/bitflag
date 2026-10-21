<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

/**
 * Implemented by queued jobs that act for one organization. The RestoreTenantContext job middleware sets the context
 * before the job runs and clears it afterwards. Jobs for account or system work return null and run without a tenant.
 */
interface TenantAware
{
    public function organizationId(): ?string;
}
