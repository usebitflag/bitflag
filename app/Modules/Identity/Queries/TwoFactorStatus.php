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
}
