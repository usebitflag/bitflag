<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Models\User;

/**
 * What the caller needs to finish a reset: the person, whether they may sign in at all, and whether a second factor is
 * still owed.
 */
final readonly class ResetPasswordResult
{
    public function __construct(public User $user, public bool $canSignIn, public bool $secondFactorRequired) {}
}
