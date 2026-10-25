<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

final readonly class CreateProjectData
{
    /**
     * @param  list<array{name: string, key: string, color?: string|null}>|null  $environments  null for the default three
     */
    public function __construct(
        public string $organizationId,
        public string $name,
        public string $key,
        public ?string $description = null,
        public ?array $environments = null,
    ) {}
}
