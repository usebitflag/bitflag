<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\AttemptSignInData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\SignInResult;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Domain\SignInThrottle;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;

/**
 * Checks an email and password. It never signs anyone in: a right password only says who the person is and whether a
 * second factor is still owed.
 *
 * A wrong password, an unknown address and a deactivated account end the same way, take about the same time, and count
 * the same toward the lock. A locked address is refused before anything about the account is read. Failures are logged in
 * the security log, not the customer audit.
 */
final class AttemptSignIn
{
    /** From this failure on, the answer is held back, one second longer each time. */
    private const FIRST_DELAYED_FAILURE = 3;

    public function __construct(
        private readonly SignInThrottle $throttle,
        private readonly RecordSecurityEvent $securityLog,
    ) {}

    public function handle(AttemptSignInData $data): SignInResult
    {
        $email = mb_strtolower(trim($data->email));

        if ($this->throttle->isLocked($email)) {
            return SignInResult::locked();
        }

        $user = User::query()->where('email', $email)->first();

        // Hash a password either way, so a missing account takes about as long as a wrong password.
        $matches = Hash::check($data->password, $user->password ?? Hash::make(bin2hex(random_bytes(8))));

        if ($user === null || ! $matches || $user->status !== AccountStatus::Active) {
            return $this->fail($data, $email, $user);
        }

        if ($this->hasConfirmedSecondFactor($user)) {
            return SignInResult::twoFactorRequired($user);
        }

        $this->throttle->clear($email);
        $this->record(SecurityEventType::SignInSucceeded, $data, $user->id, $email);

        return SignInResult::authenticated($user);
    }

    private function fail(AttemptSignInData $data, string $email, ?User $user): SignInResult
    {
        $failures = $this->throttle->recordFailure($email);

        $this->record(SecurityEventType::SignInFailed, $data, $user?->id, $email);

        if ($failures >= SignInThrottle::MAX_FAILURES) {
            $this->record(SecurityEventType::SignInLocked, $data, $user?->id, $email);
        } elseif ($failures >= self::FIRST_DELAYED_FAILURE) {
            Sleep::for($failures - self::FIRST_DELAYED_FAILURE + 1)->seconds();
        }

        return SignInResult::failed();
    }

    private function hasConfirmedSecondFactor(User $user): bool
    {
        return MfaFactor::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->exists();
    }

    private function record(SecurityEventType $type, AttemptSignInData $data, ?string $userId, string $email): void
    {
        $this->securityLog->handle(new RecordSecurityEventData($type, $userId, $email, $data->ipAddress, $data->userAgent));
    }
}
