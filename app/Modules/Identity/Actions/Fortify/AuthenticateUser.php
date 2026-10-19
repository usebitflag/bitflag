<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Fortify;

use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Checks sign-in credentials for Fortify. A wrong password, an unknown address and a deactivated account all return
 * null, so the response never tells which of them it was. Signing in is a security-log event rather than an audit
 * entry, so this action records nothing in the customer audit.
 */
final class AuthenticateUser
{
    public function handle(string $email, string $password): ?User
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        // Hash a password either way, so a missing account takes about as long as a wrong password.
        $matches = Hash::check($password, $user->password ?? Hash::make(bin2hex(random_bytes(8))));

        if ($user === null || ! $matches || $user->status !== AccountStatus::Active) {
            return null;
        }

        return $user;
    }
}
