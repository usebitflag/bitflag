<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\SecondFactorResult;
use App\Modules\Identity\Data\VerifySecondFactorData;
use App\Modules\Identity\Domain\RecoveryCodes;
use App\Modules\Identity\Domain\SecondFactorUsed;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Shared\Security\SecretHasher;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Spends one second-factor code: an authenticator code (once per thirty-second step, never replayed) or a recovery code
 * (once ever). A code that is wrong, replayed or already spent returns `None` and changes nothing. Two requests carrying
 * the same code cannot both succeed: the row is locked, or updated only if it is still unspent.
 */
final class VerifySecondFactor
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(VerifySecondFactorData $data): SecondFactorResult
    {
        $code = trim($data->code);

        if (preg_match('/^\s*\d[\d\s]*$/', $code) === 1) {
            return $this->authenticatorCode($data->userId, $code);
        }

        return RecoveryCodes::looksLikeCode($code) ? $this->recoveryCode($data->userId, $code) : new SecondFactorResult(SecondFactorUsed::None);
    }

    private function authenticatorCode(string $userId, string $code): SecondFactorResult
    {
        $accepted = DB::transaction(function () use ($userId, $code): bool {
            $factor = MfaFactor::query()->where('user_id', $userId)->whereNotNull('confirmed_at')->lockForUpdate()->first();

            if ($factor === null) {
                return false;
            }

            $timestep = Totp::verify($factor->secret, $code, $this->clock->now()->getTimestamp(), $factor->last_used_timestep);

            if ($timestep === null) {
                return false;
            }

            $factor->forceFill(['last_used_timestep' => $timestep, 'last_used_at' => $this->clock->now()])->save();

            return true;
        });

        return new SecondFactorResult($accepted ? SecondFactorUsed::AuthenticatorCode : SecondFactorUsed::None);
    }

    private function recoveryCode(string $userId, string $code): SecondFactorResult
    {
        $hash = $this->hasher->hash(RecoveryCodes::normalize($code));

        $spent = MfaRecoveryCode::query()
            ->where('user_id', $userId)
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->update(['used_at' => $this->clock->now()]);

        if ($spent !== 1) {
            return new SecondFactorResult(SecondFactorUsed::None);
        }

        $left = MfaRecoveryCode::query()->where('user_id', $userId)->whereNull('used_at')->count();

        return new SecondFactorResult(SecondFactorUsed::RecoveryCode, $left);
    }
}
