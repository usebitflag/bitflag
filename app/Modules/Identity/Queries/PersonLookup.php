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

    /**
     * The id of the account with this email address, or null. For flows that must check an invitee against existing people.
     */
    public function idByEmail(string $email): ?string
    {
        $id = User::query()->where('email', mb_strtolower(trim($email)))->value('id');

        return is_string($id) ? $id : null;
    }

    public function nameOf(string $userId): ?string
    {
        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) ? $name : null;
    }
}
