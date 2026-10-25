<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Modules\Organizations\Domain\TeamSize;

final readonly class CreateOrganizationData
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?TeamSize $teamSize = null,
    ) {}
}
