<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class TrackKnownDeviceData
{
    public function __construct(
        public string $userId,
        public ?string $deviceToken,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
