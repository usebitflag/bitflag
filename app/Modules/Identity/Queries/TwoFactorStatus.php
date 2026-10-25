<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\MfaFactor;

/**
 * Whether a person has finished enrolling a second factor. An enrollment that was never confirmed does not count.
 */
final class TwoFactorStatus
{
    public function isEnrolled(string $userId): bool
    {
        return MfaFactor::query()->where('user_id', $userId)->whereNotNull('confirmed_at')->exists();
    }

    /**
     * Which of these people have two-factor on.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function enrolledAmong(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $enrolled = MfaFactor::query()->whereIn('user_id', $userIds)->whereNotNull('confirmed_at')->get()
            ->map(static fn (MfaFactor $factor): string => $factor->user_id)
            ->all();

        return array_values($enrolled);
    }
}
