<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\DisableTwoFactorData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Data\VerifySecondFactorData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\OrganizationMfaPolicy;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\RequestOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Turns two-factor sign-in off. Needs the password and a current code, and is refused while any organization the person
 * belongs to requires two-factor. Trusted browsers stop skipping it.
 */
final class DisableTwoFactor
{
    public function __construct(
        private readonly OrganizationMfaPolicy $policy,
        private readonly VerifySecondFactor $verify,
        private readonly RecordSecurityEvent $securityLog,
        private readonly RequestAccountEmail $accountEmail,
        private readonly RequestOrigin $origin,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(DisableTwoFactorData $data): void
    {
        $user = User::query()->findOrFail($data->userId);

        if (! $user->passwordMatches($data->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }

        $required = $this->policy->organizationsRequiringMfa($user->id);

        if ($required !== []) {
            throw ValidationException::withMessages(['two_factor' => implode(', ', $required).' requires two-factor sign-in.']);
        }

        DB::transaction(function () use ($user, $data): void {
            if (! $this->verify->handle(new VerifySecondFactorData($user->id, $data->code))->accepted()) {
                throw ValidationException::withMessages(['code' => 'That code is not right.']);
            }

            MfaFactor::query()->where('user_id', $user->id)->delete();
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
            DB::table('trusted_browsers')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => $this->clock->now()]);

            $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::MfaDisabled, $user->id, null, $data->ipAddress, $data->userAgent));
            $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::MfaDisabled, $user->id, $this->origin->describe($data->ipAddress, $data->userAgent)));
        });
    }
}
