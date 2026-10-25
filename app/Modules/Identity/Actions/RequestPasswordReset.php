<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Data\RequestPasswordResetData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Asks for a reset email if, and only if, the address has an active account. The caller answers the same either way, so
 * nobody learns which addresses have accounts. The token itself is made when the email is sent.
 */
final class RequestPasswordReset
{
    public function __construct(private readonly RequestAccountEmail $accountEmail) {}

    public function handle(RequestPasswordResetData $data): void
    {
        $user = User::query()->where('email', mb_strtolower(trim($data->email)))->first();

        if ($user === null || $user->status !== AccountStatus::Active) {
            return;
        }

        DB::transaction(fn () => $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::PasswordReset, $user->id)));
    }
}
