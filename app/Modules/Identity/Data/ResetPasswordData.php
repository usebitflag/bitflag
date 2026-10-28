<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class ResetPasswordData
{
    public function __construct(
        public string $userId,
        public string $token,
        public string $password,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
