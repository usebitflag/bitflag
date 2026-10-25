<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Models\User;

/**
 * Set only when a new account was created. The caller shows the same page either way and signs in only a new person.
 */
final readonly class RegisterAccountResult
{
    public function __construct(public ?User $createdUser) {}
}
