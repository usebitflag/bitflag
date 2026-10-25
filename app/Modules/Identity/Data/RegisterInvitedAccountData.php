<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class RegisterInvitedAccountData
{
    public function __construct(
        public string $email,
        public string $name,
        public string $password,
    ) {}
}
