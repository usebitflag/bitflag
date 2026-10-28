<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\ChangePasswordData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\PasswordRules;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Changes the password of a signed-in person who proves the current one. Every other session is signed out and every
 * trusted browser stops skipping the second factor; the session making the change stays. The caller rotates that
 * session's id.
 */
final class ChangePassword
{
    public function __construct(
        private readonly PasswordRules $rules,
        private readonly RecordSecurityEvent $securityLog,
        private readonly RequestAccountEmail $accountEmail,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(ChangePasswordData $data): void
    {
        $user = User::query()->findOrFail($data->userId);

        if (! $user->passwordMatches($data->currentPassword)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        $violations = $this->rules->violations($data->newPassword, $user->email);

        if ($violations !== []) {
            throw ValidationException::withMessages(['password' => $violations]);
        }

        DB::transaction(function () use ($user, $data): void {
            $user->forceFill(['password' => Hash::make($data->newPassword), 'password_changed_at' => $this->clock->now()])->save();

            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $data->keepSessionId)->delete();
            DB::table('trusted_browsers')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => $this->clock->now()]);

            $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::PasswordChanged, $user->id, null, $data->ipAddress, $data->userAgent));
            $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::PasswordChanged, $user->id));
        });
    }
}
