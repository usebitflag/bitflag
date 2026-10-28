<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Data\ResetPasswordData;
use App\Modules\Identity\Data\ResetPasswordResult;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\Exceptions\InvalidResetToken;
use App\Modules\Identity\Domain\PasswordRules;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Domain\SignInThrottle;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Queries\TwoFactorStatus;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Sets a new password from a reset link. The link works once and for 60 minutes. Resetting signs the person out
 * everywhere, stops every trusted browser from skipping the second factor, clears a sign-in lock, and tells them by
 * email. It does not sign anyone in: the caller does that from the result, and a reset never skips the second factor.
 */
final class ResetPassword
{
    public function __construct(
        private readonly PasswordRules $rules,
        private readonly SignInThrottle $throttle,
        private readonly RecordSecurityEvent $securityLog,
        private readonly RequestAccountEmail $accountEmail,
        private readonly TwoFactorStatus $twoFactor,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws InvalidResetToken
     * @throws ValidationException
     */
    public function handle(ResetPasswordData $data): ResetPasswordResult
    {
        $user = User::query()->find($data->userId);

        if ($user === null) {
            throw new InvalidResetToken;
        }

        $violations = $this->rules->violations($data->password, $user->email);

        if ($violations !== []) {
            throw ValidationException::withMessages(['password' => $violations]);
        }

        $broker = Password::broker();
        assert($broker instanceof PasswordBroker);

        $status = $broker->reset(
            ['email' => $user->email, 'token' => $data->token, 'password' => $data->password],
            function (User $user, string $password) use ($data): void {
                DB::transaction(function () use ($user, $password, $data): void {
                    $user->forceFill(['password' => Hash::make($password), 'password_changed_at' => $this->clock->now()])->save();

                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    DB::table('trusted_browsers')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => $this->clock->now()]);

                    $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::PasswordReset, $user->id, null, $data->ipAddress, $data->userAgent));
                    $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::PasswordChanged, $user->id));
                });
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidResetToken;
        }

        $this->throttle->clear($user->email);

        $user->refresh();

        return new ResetPasswordResult($user, $user->status === AccountStatus::Active, $this->twoFactor->isEnrolled($user->id));
    }
}
