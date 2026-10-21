<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class OrganizationSummary
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $status,
    ) {}
}
