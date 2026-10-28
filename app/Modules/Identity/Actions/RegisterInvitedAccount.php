<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\InvitedAccount;
use App\Modules\Identity\Data\RegisterInvitedAccountData;
use App\Modules\Identity\Domain\PasswordRules;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Creates the account of someone who followed an invitation link. The link proved they own the mailbox, so the address is
 * already confirmed. Unlike sign-up it may say that the address already has an account, because only the holder of the
 * invitation can reach it.
 */
final class RegisterInvitedAccount
{
    public function __construct(
        private readonly PasswordRules $rules,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(RegisterInvitedAccountData $data): InvitedAccount
    {
        $email = mb_strtolower(trim($data->email));
        $name = trim($data->name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter your name, up to 120 characters.']);
        }

        $violations = $this->rules->violations($data->password, $email);

        if ($violations !== []) {
            throw ValidationException::withMessages(['password' => $violations]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'You already have an account. Sign in to accept.']);
        }

        try {
            $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make($data->password)]);
            $user->forceFill(['email_verified_at' => $this->clock->now()])->save();

            return new InvitedAccount($user->id, $user->name, $user->email);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'You already have an account. Sign in to accept.']);
        }
    }
}
