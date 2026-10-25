<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\AttemptSignInData;
use App\Modules\Identity\Data\CountSignInFailureData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\SignInResult;
use App\Modules\Identity\Data\TrackKnownDeviceData;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Domain\SignInThrottle;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\TrustedBrowser;
use App\Modules\Identity\Models\User;
use App\Shared\Security\SecretHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;
use Psr\Clock\ClockInterface;

/**
 * Checks an email and password. It never signs anyone in: a right password only says who the person is and whether a
 * second factor is still owed. A trusted browser (a cookie from an earlier second-factor check) skips that factor.
 *
 * A wrong password, an unknown address and a deactivated account end the same way, take about the same time, and count
 * the same toward the lock. A locked address is refused before anything about the account is read. Failures are logged in
 * the security log, not the customer audit, and the person is emailed once when their account locks.
 */
final class AttemptSignIn
{
    /** From this failure on, the answer is held back, one second longer each time. */
    private const FIRST_DELAYED_FAILURE = 3;

    public function __construct(
        private readonly SignInThrottle $throttle,
        private readonly CountSignInFailure $failures,
        private readonly RecordSecurityEvent $securityLog,
        private readonly TrackKnownDevice $devices,
        private readonly SecretHasher $hasher,
        private readonly ClockInterface $clock,
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

        if ($this->hasConfirmedSecondFactor($user) && ! $this->browserIsTrusted($user, $data->trustedBrowserCookie)) {
            return SignInResult::twoFactorRequired($user);
        }

        $this->throttle->clear($email);
        $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::SignInSucceeded, $user->id, $email, $data->ipAddress, $data->userAgent));

        $deviceToken = $this->devices->handle(new TrackKnownDeviceData($user->id, $data->deviceCookie, $data->ipAddress, $data->userAgent));

        return SignInResult::authenticated($user, $deviceToken);
    }

    private function fail(AttemptSignInData $data, string $email, ?User $user): SignInResult
    {
        $failures = $this->failures->handle(new CountSignInFailureData(SecurityEventType::SignInFailed, $email, $user?->id, $data->ipAddress, $data->userAgent));

        if ($failures >= self::FIRST_DELAYED_FAILURE && $failures < SignInThrottle::MAX_FAILURES) {
            Sleep::for($failures - self::FIRST_DELAYED_FAILURE + 1)->seconds();
        }

        return SignInResult::failed();
    }

    private function hasConfirmedSecondFactor(User $user): bool
    {
        return MfaFactor::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->exists();
    }

    /**
     * The cookie is `{id}.{secret}`. It counts only for this person, unrevoked and unexpired, and only if the secret matches
     * the stored hash.
     */
    private function browserIsTrusted(User $user, ?string $cookie): bool
    {
        if ($cookie === null || substr_count($cookie, '.') !== 1) {
            return false;
        }

        [$id, $secret] = explode('.', $cookie);

        $browser = TrustedBrowser::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $this->clock->now())
            ->first();

        if ($browser === null || ! $this->hasher->matches($secret, $browser->token_hash)) {
            return false;
        }

        $browser->forceFill(['last_used_at' => $this->clock->now()])->save();

        return true;
    }
}
