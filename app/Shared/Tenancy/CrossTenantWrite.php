<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use LogicException;

/**
 * A model belonging to one organization was saved while another organization was in context.
 */
final class CrossTenantWrite extends LogicException
{
    public static function between(string $model, string $contextOrganization, string $rowOrganization): self
    {
        return new self("{$model} belongs to organization {$rowOrganization} but organization {$contextOrganization} is in context.");
    }
}
