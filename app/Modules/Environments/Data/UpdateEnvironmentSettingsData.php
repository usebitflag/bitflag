<?php

declare(strict_types=1);

namespace App\Modules\Environments\Data;

final readonly class UpdateEnvironmentSettingsData
{
    /**
     * @param  list<string>  $allowedOrigins
     * @param  int|null  $defaultMaxSnapshotAgeSeconds  null means no limit
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public string $name,
        public string $key,
        public string $color,
        public array $allowedOrigins,
        public ?int $defaultMaxSnapshotAgeSeconds,
        public bool $isProtected,
        public int $requiredApprovals,
        public int $expectedVersion,
    ) {}
}
