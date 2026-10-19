<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\User;

/**
 * Whether an account may act. A deactivated account holds no capabilities anywhere.
 */
final class UserStatus
{
    public function isActive(string $userId): bool
    {
        return User::query()->whereKey($userId)->where('status', 'active')->exists();
    }
}
