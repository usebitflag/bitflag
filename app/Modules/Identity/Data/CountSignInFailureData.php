<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Domain\SecurityEventType;

final readonly class CountSignInFailureData
{
    public function __construct(
        public SecurityEventType $type,
        public string $email,
        public ?string $userId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
