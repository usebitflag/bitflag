<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Data\RequestEmailChangeData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts changing a person's email address. It needs their password. The new address is asked to confirm itself and the
 * account keeps its current address until it does. An address that already has an account changes nothing and tells that
 * account's owner, as sign-up does, and the answer is the same either way.
 */
final class RequestEmailChange
{
    public function __construct(private readonly RequestAccountEmail $accountEmail) {}

    /**
     * @throws ValidationException
     */
    public function handle(RequestEmailChangeData $data): void
    {
        $user = User::query()->findOrFail($data->userId);
        $email = mb_strtolower(trim($data->newEmail));

        if (! $user->passwordMatches($data->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        if ($email === $user->email) {
            throw ValidationException::withMessages(['email' => 'That is already your email address.']);
        }

        $owner = User::query()->where('email', $email)->orWhere('pending_email', $email)->first();

        DB::transaction(function () use ($user, $email, $owner): void {
            if ($owner !== null && $owner->id !== $user->id) {
                $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::SignupAttemptExisting, $owner->id));

                return;
            }

            $user->forceFill(['pending_email' => $email])->save();
            $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::ConfirmNewEmail, $user->id));
        });
    }
}
