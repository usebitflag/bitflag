<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class AttemptSignInData
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $trustedBrowserCookie = null,
        public ?string $deviceCookie = null,
    ) {}
}
