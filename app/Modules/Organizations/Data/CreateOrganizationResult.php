<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class CreateOrganizationResult
{
    public function __construct(public string $organizationId, public string $slug) {}
}
