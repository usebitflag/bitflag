<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Domain\SecondFactorUsed;

final readonly class SecondFactorResult
{
    public function __construct(public SecondFactorUsed $used, public int $recoveryCodesLeft = 0) {}

    public function accepted(): bool
    {
        return $this->used !== SecondFactorUsed::None;
    }
}
