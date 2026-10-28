<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class UpdateOrganizationData
{
    public function __construct(
        public string $organizationId,
        public string $name,
        public string $slug,
        public int $expectedVersion,
    ) {}
}
