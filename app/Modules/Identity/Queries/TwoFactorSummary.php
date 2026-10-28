<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;

/**
 * What the security page shows about a person's two-factor sign-in.
 */
final class TwoFactorSummary
{
    /**
     * @return array{enabled: bool, recoveryCodesLeft: int}
     */
    public function for(string $userId): array
    {
        $enabled = MfaFactor::query()->where('user_id', $userId)->whereNotNull('confirmed_at')->exists();

        return [
            'enabled' => $enabled,
            'recoveryCodesLeft' => $enabled ? MfaRecoveryCode::query()->where('user_id', $userId)->whereNull('used_at')->count() : 0,
        ];
    }
}
