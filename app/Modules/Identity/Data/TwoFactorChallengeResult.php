<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Models\User;

final readonly class TwoFactorChallengeResult
{
    public function __construct(
        public ChallengeOutcome $outcome,
        public ?User $user = null,
        public ?string $trustedBrowserCookie = null,
        public ?string $deviceToken = null,
        public ?int $recoveryCodesLeft = null,
    ) {}
}
