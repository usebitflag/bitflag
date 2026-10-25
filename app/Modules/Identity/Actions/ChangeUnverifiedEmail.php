<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\ChangeUnverifiedEmailData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Models\User;
use App\Shared\Errors\AuthorizationDenied;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Lets someone who has not confirmed their address fix a typo. It follows the sign-up rule: an address that belongs to
 * another account changes nothing and tells that account's owner, and the answer is the same either way.
 */
final class ChangeUnverifiedEmail
{
    public function __construct(private readonly RequestAccountEmail $accountEmail) {}

    public function handle(ChangeUnverifiedEmailData $data): void
    {
        $user = User::query()->findOrFail($data->userId);

        if ($user->email_verified_at !== null) {
            throw new AuthorizationDenied('Your email is already confirmed.');
        }

        $email = mb_strtolower(trim($data->email));
        $owner = $email === $user->email ? $user : User::query()->where('email', $email)->first();

        if ($owner !== null && $owner->id !== $user->id) {
            DB::transaction(fn () => $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::SignupAttemptExisting, $owner->id)));

            return;
        }

        try {
            DB::transaction(function () use ($user, $email): void {
                $user->forceFill(['email' => $email])->save();
                $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::VerifyEmail, $user->id));
            });
        } catch (UniqueConstraintViolationException) {
            // The address was taken a moment ago; nothing changes.
        }
    }
}
