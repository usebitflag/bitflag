<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class ChangePasswordData
{
    public function __construct(
        public string $userId,
        public string $currentPassword,
        public string $newPassword,
        public string $keepSessionId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
