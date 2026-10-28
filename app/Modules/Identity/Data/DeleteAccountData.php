<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class DeleteAccountData
{
    public function __construct(
        public string $userId,
        public string $password,
        public string $typedEmail,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
