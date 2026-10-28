<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\ConfirmTwoFactorEnrollmentData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Support\RequestOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Finishes turning on two-factor sign-in with a code from the app, and returns the ten recovery codes. Those are the only
 * time the codes exist in the clear.
 */
final class ConfirmTwoFactorEnrollment
{
    public function __construct(
        private readonly GenerateRecoveryCodes $recoveryCodes,
        private readonly RecordSecurityEvent $securityLog,
        private readonly RequestAccountEmail $accountEmail,
        private readonly RequestOrigin $origin,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return list<string> the recovery codes
     *
     * @throws ValidationException
     */
    public function handle(ConfirmTwoFactorEnrollmentData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $factor = MfaFactor::query()->where('user_id', $data->userId)->whereNull('confirmed_at')->lockForUpdate()->first();
            $timestep = $factor === null ? null : Totp::verify($factor->secret, $data->code, $this->clock->now()->getTimestamp());

            if ($factor === null || $timestep === null) {
                throw ValidationException::withMessages(['code' => 'That code is not right. Check the clock on your device and try again.']);
            }

            $factor->forceFill([
                'confirmed_at' => $this->clock->now(),
                'last_used_timestep' => $timestep,
                'last_used_at' => $this->clock->now(),
            ])->save();

            $codes = $this->recoveryCodes->handle($data->userId);

            $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::MfaEnabled, $data->userId, null, $data->ipAddress, $data->userAgent));
            $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::MfaEnabled, $data->userId, $this->origin->describe($data->ipAddress, $data->userAgent)));

            return $codes;
        });
    }
}
