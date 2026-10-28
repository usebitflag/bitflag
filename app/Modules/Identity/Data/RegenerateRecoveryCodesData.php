<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class RegenerateRecoveryCodesData
{
    public function __construct(public string $userId, public string $password) {}
}
