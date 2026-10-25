<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\RecoveryCodes;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Shared\Security\SecretHasher;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Replaces a person's recovery codes with a new set of ten and returns them in the clear. Only hashes are stored, and the
 * old set stops working.
 */
final class GenerateRecoveryCodes
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return list<string>
     */
    public function handle(string $userId): array
    {
        $codes = RecoveryCodes::generate();

        DB::transaction(function () use ($userId, $codes): void {
            MfaRecoveryCode::query()->where('user_id', $userId)->delete();

            foreach ($codes as $code) {
                MfaRecoveryCode::query()->create([
                    'user_id' => $userId,
                    'code_hash' => $this->hasher->hash(RecoveryCodes::normalize($code)),
                    'created_at' => $this->clock->now(),
                ]);
            }
        });

        return $codes;
    }
}
