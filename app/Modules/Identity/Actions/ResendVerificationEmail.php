<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sends the confirmation email again. A confirmed account needs nothing, so nothing is sent. The caller limits how often.
 */
final class ResendVerificationEmail
{
    public function __construct(private readonly RequestAccountEmail $accountEmail) {}

    public function handle(string $userId): void
    {
        $user = User::query()->find($userId);

        if ($user === null || $user->email_verified_at !== null) {
            return;
        }

        DB::transaction(fn () => $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::VerifyEmail, $user->id)));
    }
}
