<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class ChangeUnverifiedEmailData
{
    public function __construct(public string $userId, public string $email) {}
}
