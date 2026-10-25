<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class CompleteTwoFactorChallengeData
{
    public function __construct(
        public string $userId,
        public string $code,
        public bool $trustThisBrowser,
        public ?string $deviceCookie,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
