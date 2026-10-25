<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

final readonly class CreateProjectResult
{
    /**
     * @param  array<string, string>  $environmentIds  environment key => id
     */
    public function __construct(
        public string $projectId,
        public string $key,
        public array $environmentIds,
    ) {}
}
