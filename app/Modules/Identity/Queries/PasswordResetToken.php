<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;

/**
 * Whether a reset link can still be used, so the page can say so before the person types a new password.
 */
final class PasswordResetToken
{
    public function isUsable(string $userId, string $token): bool
    {
        $user = User::query()->find($userId);
        $broker = Password::broker();
        assert($broker instanceof PasswordBroker);

        return $user !== null && $broker->tokenExists($user, $token);
    }
}
