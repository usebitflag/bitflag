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

    /**
     * Names, addresses and last activity for a list of people, keyed by id. People who no longer exist are left out.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{name: string, email: string, lastActiveAt: int|null}>
     */
    public function profiles(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $profiles = [];

        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            $profiles[$user->id] = ['name' => $user->name, 'email' => $user->email, 'lastActiveAt' => $user->last_active_at?->getTimestamp()];
        }

        return $profiles;
    }
}
