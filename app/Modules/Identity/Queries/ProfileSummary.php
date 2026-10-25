<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\User;
use DateTimeZone;

/**
 * What the profile page shows and offers.
 */
final class ProfileSummary
{
    /**
     * @return array{name: string, email: string, timezone: string, pendingEmail: string|null, emailVerified: bool}
     */
    public function for(string $userId): array
    {
        $user = User::query()->findOrFail($userId);

        return [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'pendingEmail' => $user->pending_email,
            'emailVerified' => $user->email_verified_at !== null,
        ];
    }

    /**
     * @return list<string> IANA time zone names
     */
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
}
