<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class RememberLastScopeData
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
    ) {}
}
