<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class CreateOrganizationData
{
    /**
     * @param  string|null  $teamSize  `solo`, `small`, `medium` or `large`; anything else is ignored
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $teamSize = null,
    ) {}
}
