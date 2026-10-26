<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Support\OAuth\OAuthProfile;

final readonly class SignInWithProviderData
{
    public function __construct(
        public OAuthProfile $profile,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $trustedBrowserCookie,
        public ?string $deviceCookie,
    ) {}
}
