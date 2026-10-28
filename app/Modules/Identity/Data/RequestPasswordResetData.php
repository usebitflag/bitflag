<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class RequestPasswordResetData
{
    public function __construct(
        public string $email,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}
}
