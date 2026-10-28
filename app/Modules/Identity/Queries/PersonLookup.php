<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\User;

/**
 * Small reads about a person by id, for screens that show who is signing in.
 */
final class PersonLookup
{
    public function emailOf(string $userId): ?string
    {
        $email = User::query()->whereKey($userId)->value('email');

        return is_string($email) ? $email : null;
    }
}
