<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

final readonly class UpdateProjectSettingsData
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $name,
        public string $key,
        public ?string $description,
        public string $timeZone,
        public string $flagKeyFormat,
        public int $staleAfterDays,
        public bool $requireFlagDescription,
        public bool $requireFlagOwner,
        public bool $clientVisibleByDefault,
        public int $expectedVersion,
    ) {}
}
