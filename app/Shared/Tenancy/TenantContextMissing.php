<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use LogicException;

/**
 * A tenant-scoped query or write ran with no organization in context. The scope fails closed instead of returning
 * every tenant's rows. This is a programming error and renders as a generic 500.
 */
final class TenantContextMissing extends LogicException
{
    public static function forQuery(string $model): self
    {
        return new self("No tenant context is set for a query on {$model}. Set the organization or use Tenancy::crossTenant().");
    }

    public static function forWrite(string $model): self
    {
        return new self("No tenant context is set for a write to {$model}. Set the organization or use Tenancy::crossTenant().");
    }
}
