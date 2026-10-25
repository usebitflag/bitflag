<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RegisterAccountData;
use App\Modules\Identity\Data\RegisterAccountResult;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates an account without revealing whether the address already had one. A new address gets an account and a
 * confirmation email; an address with an account gets nothing changed and an email saying so. The password is hashed
 * either way, so the two take about as long.
 */
final class RegisterAccount
{
    public function __construct(private readonly RequestAccountEmail $accountEmail) {}

    public function handle(RegisterAccountData $data): RegisterAccountResult
    {
        $email = mb_strtolower(trim($data->email));
        $hash = Hash::make($data->password);

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->existing($existing);
        }

        try {
            $user = DB::transaction(function () use ($data, $email, $hash): User {
                $user = User::query()->create([
                    'name' => $this->nameFor($data->name, $email),
                    'email' => $email,
                    'password' => $hash,
                ]);

                $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::VerifyEmail, $user->id));

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups for one address raced; the other one won, so this one is an existing account.
            $winner = User::query()->where('email', $email)->first();

            return $winner === null ? new RegisterAccountResult(null) : $this->existing($winner);
        }

        return new RegisterAccountResult($user);
    }

    private function existing(User $user): RegisterAccountResult
    {
        DB::transaction(fn () => $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::SignupAttemptExisting, $user->id)));

        return new RegisterAccountResult(null);
    }

    private function nameFor(?string $name, string $email): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : explode('@', $email)[0];
    }
}
