<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\SignInWithProviderData;
use App\Modules\Identity\Data\SocialSignInOutcome;
use App\Modules\Identity\Data\SocialSignInResult;
use App\Modules\Identity\Data\TrackKnownDeviceData;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Signs a person in, or signs them up, from what a provider says about them. The provider replaces the password and
 * nothing else: a second factor is still owed when they have one and the browser is not trusted.
 *
 * 1. An account already linked to this provider account signs in, whatever email the provider shows today.
 * 2. Otherwise the provider must vouch for the email. Without that nothing is found or made.
 * 3. A verified email that already belongs to an account links nothing and signs no one in: that account's owner is not
 *    proven by the provider alone, so they sign in the usual way first.
 * 4. A new email creates an account with the email confirmed and no password.
 */
final class SignInWithProvider
{
    public function __construct(
        private readonly SecondFactorOwed $secondFactor,
        private readonly RecordSecurityEvent $securityLog,
        private readonly TrackKnownDevice $devices,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(SignInWithProviderData $data): SocialSignInResult
    {
        $profile = $data->profile;

        $identity = SocialIdentity::query()
            ->where('provider', $profile->provider->value)
            ->where('provider_user_id', $profile->id)
            ->first();

        if ($identity !== null) {
            $user = User::query()->find($identity->user_id);

            if ($user === null || $user->status !== AccountStatus::Active) {
                return SocialSignInResult::refused();
            }

            $identity->forceFill(['last_used_at' => $this->clock->now()])->save();

            return $this->complete(SocialSignInOutcome::Authenticated, $user, $data);
        }

        if ($profile->email === null) {
            return SocialSignInResult::emailNotVerified();
        }

        if (User::query()->where('email', $profile->email)->exists()) {
            return SocialSignInResult::accountExists($profile->email);
        }

        try {
            $user = DB::transaction(function () use ($profile): User {
                $user = User::query()->create([
                    'name' => $profile->name !== '' ? $profile->name : explode('@', $profile->email ?? '')[0],
                    'email' => $profile->email,
                    'password' => null,
                ]);
                $user->forceFill(['email_verified_at' => $this->clock->now()])->save();

                SocialIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => $profile->provider,
                    'provider_user_id' => $profile->id,
                    'email' => $profile->email,
                    'created_at' => $this->clock->now(),
                    'last_used_at' => $this->clock->now(),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two first sign-ins raced; whichever lost finds the account the winner made.
            return SocialSignInResult::accountExists($profile->email);
        }

        $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::SignedUpWithProvider, $user->id, $profile->email, $data->ipAddress, $data->userAgent));

        return $this->complete(SocialSignInOutcome::Created, $user, $data);
    }

    private function complete(SocialSignInOutcome $outcome, User $user, SignInWithProviderData $data): SocialSignInResult
    {
        if ($this->secondFactor->handle($user, $data->trustedBrowserCookie)) {
            return SocialSignInResult::twoFactorRequired($user);
        }

        $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::SignInSucceeded, $user->id, $user->email, $data->ipAddress, $data->userAgent));

        $deviceToken = $this->devices->handle(new TrackKnownDeviceData($user->id, $data->deviceCookie, $data->ipAddress, $data->userAgent));

        return SocialSignInResult::signedIn($outcome, $user, $deviceToken);
    }
}
