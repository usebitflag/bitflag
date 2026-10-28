<?php

declare(strict_types=1);

namespace App\Modules\Environments\Data;

final readonly class ReorderEnvironmentsData
{
    /**
     * @param  list<string>  $orderedIds  every active environment of the project, in the new order
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public array $orderedIds,
    ) {}
}
