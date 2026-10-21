<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

final readonly class ProjectSummary
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $key,
        public string $name,
        public string $status,
    ) {}
}
