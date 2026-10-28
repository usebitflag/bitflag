<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class InvitedAccount
{
    public function __construct(public string $id, public string $name, public string $email) {}
}
