<?php

declare(strict_types=1);

namespace App\Modules\Environments\Data;

use App\Modules\Environments\Domain\EnvironmentStatus;

final readonly class EnvironmentDeliverySettingsData
{
    /** @param list<string> $allowedOrigins */
    public function __construct(
        public string $environmentId,
        public array $allowedOrigins,
        public ?int $defaultMaxSnapshotAgeSeconds,
        public EnvironmentStatus $status,
    ) {}
}
