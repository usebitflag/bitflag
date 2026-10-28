<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class RevokeOtherSessionsData
{
    public function __construct(
        public string $userId,
        public string $currentSessionId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
