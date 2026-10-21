<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant model to the organization in context. With no organization in context the query
 * throws instead of returning all tenants' rows.
 */
/**
 * @implements Scope<Model>
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $organizationId = $context->organizationId();

        if ($organizationId === null) {
            throw TenantContextMissing::forQuery($model::class);
        }

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
