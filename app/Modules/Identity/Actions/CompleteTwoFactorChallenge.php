<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\ChallengeOutcome;
use App\Modules\Identity\Data\CompleteTwoFactorChallengeData;
use App\Modules\Identity\Data\CountSignInFailureData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Data\TrackKnownDeviceData;
use App\Modules\Identity\Data\TwoFactorChallengeResult;
use App\Modules\Identity\Data\VerifySecondFactorData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\SecondFactorUsed;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Domain\SignInThrottle;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\RequestOrigin;
use Illuminate\Support\Facades\DB;

/**
 * The second step of a sign-in whose password was right: an authenticator code or a recovery code. It never signs anyone
 * in (the caller does, from the result). A wrong code counts toward the same lock as a wrong password. A recovery code
 * works once, is logged, and is announced by email with how many are left.
 */
final class CompleteTwoFactorChallenge
{
    public function __construct(
        private readonly SignInThrottle $throttle,
        private readonly VerifySecondFactor $verify,
        private readonly CountSignInFailure $failures,
        private readonly CreateTrustedBrowser $trustedBrowsers,
        private readonly TrackKnownDevice $devices,
        private readonly RecordSecurityEvent $securityLog,
        private readonly RequestAccountEmail $accountEmail,
        private readonly RequestOrigin $origin,
    ) {}

    public function handle(CompleteTwoFactorChallengeData $data): TwoFactorChallengeResult
    {
        $user = User::query()->find($data->userId);

        if ($user === null || $user->status !== AccountStatus::Active) {
            return new TwoFactorChallengeResult(ChallengeOutcome::Unavailable);
        }

        if ($this->throttle->isLocked($user->email)) {
            return new TwoFactorChallengeResult(ChallengeOutcome::Locked);
        }

        $result = $this->verify->handle(new VerifySecondFactorData($user->id, $data->code));

        if (! $result->accepted()) {
            $this->failures->handle(new CountSignInFailureData(SecurityEventType::MfaChallengeFailed, $user->email, $user->id, $data->ipAddress, $data->userAgent));

            return new TwoFactorChallengeResult(ChallengeOutcome::WrongCode);
        }

        $this->throttle->clear($user->email);

        DB::transaction(function () use ($user, $data, $result): void {
            $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::SignInSucceeded, $user->id, null, $data->ipAddress, $data->userAgent));

            if ($result->used === SecondFactorUsed::RecoveryCode) {
                $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::MfaRecoveryCodeUsed, $user->id, null, $data->ipAddress, $data->userAgent));
                $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::RecoveryCodeUsed, $user->id, ['codes_left' => $result->recoveryCodesLeft] + $this->origin->describe($data->ipAddress, $data->userAgent)));
            }
        });

        return new TwoFactorChallengeResult(
            ChallengeOutcome::Authenticated,
            $user,
            $data->trustThisBrowser ? $this->trustedBrowsers->handle($user->id, $data->userAgent) : null,
            $this->devices->handle(new TrackKnownDeviceData($user->id, $data->deviceCookie, $data->ipAddress, $data->userAgent)),
            $result->used === SecondFactorUsed::RecoveryCode ? $result->recoveryCodesLeft : null,
        );
    }
}
