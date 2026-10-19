<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as owned by an organization. Queries are scoped to the organization in context, the organization is
 * filled in on create, and saving a row that belongs to a different organization throws.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(static function (Model $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute('organization_id') === null) {
                $organizationId = $context->organizationId();

                if ($organizationId === null) {
                    throw TenantContextMissing::forWrite($model::class);
                }

                $model->setAttribute('organization_id', $organizationId);
            }
        });

        static::saving(static function (Model $model): void {
            $context = app(TenantContext::class);
            $contextOrganization = $context->organizationId();

            if ($context->isBypassed() || $contextOrganization === null) {
                return;
            }

            $rowOrganization = $model->getAttribute('organization_id');

            if (is_string($rowOrganization) && $rowOrganization !== $contextOrganization) {
                throw CrossTenantWrite::between($model::class, $contextOrganization, $rowOrganization);
            }
        });
    }
}
