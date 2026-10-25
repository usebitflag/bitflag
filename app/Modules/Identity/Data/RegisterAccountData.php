<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class RegisterAccountData
{
    public function __construct(
        public ?string $name,
        public string $email,
        public string $password,
    ) {}
}
