<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class DisableTwoFactorData
{
    public function __construct(
        public string $userId,
        public string $password,
        public string $code,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
