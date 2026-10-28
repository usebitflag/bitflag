<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\StartTwoFactorEnrollmentData;
use App\Modules\Identity\Data\TwoFactorEnrollment;
use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Begins turning on two-factor sign-in: makes a secret and keeps it, unconfirmed, until a code from the app proves the
 * person has it. Needs the password. Starting again replaces an unconfirmed secret.
 */
final class StartTwoFactorEnrollment
{
    /**
     * @throws ValidationException
     */
    public function handle(StartTwoFactorEnrollmentData $data): TwoFactorEnrollment
    {
        $user = User::query()->findOrFail($data->userId);

        if (! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }

        if (MfaFactor::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->exists()) {
            throw ValidationException::withMessages(['two_factor' => 'Two-factor sign-in is already on.']);
        }

        $secret = Totp::generateSecret();

        DB::transaction(function () use ($user, $secret): void {
            MfaFactor::query()->where('user_id', $user->id)->delete();
            MfaFactor::query()->create(['user_id' => $user->id, 'type' => MfaFactorType::Totp, 'secret' => $secret]);
        });

        return new TwoFactorEnrollment($secret, Totp::provisioningUri($secret, $user->email));
    }
}
