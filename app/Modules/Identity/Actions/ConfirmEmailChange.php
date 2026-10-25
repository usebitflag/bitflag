<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\ConfirmEmailChangeData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\User;
use App\Shared\Errors\AuthorizationDenied;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Finishes an email change from the link in the email sent to the new address. The link must be for the person who opened
 * it and for the address still waiting on the account. The new address becomes the account's (and is confirmed, since the
 * link proves the mailbox), and the old address is told. Returns false when there is nothing to confirm.
 */
final class ConfirmEmailChange
{
    public function __construct(
        private readonly RecordSecurityEvent $securityLog,
        private readonly RequestAccountEmail $accountEmail,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(ConfirmEmailChangeData $data): bool
    {
        $user = User::query()->find($data->userId);

        if ($user === null || $user->id !== $data->actingUserId) {
            throw new AuthorizationDenied('This confirmation link is not for your account.');
        }

        $pending = $user->pending_email;

        if ($pending === null || ! hash_equals(sha1($pending), $data->hash)) {
            return false;
        }

        try {
            DB::transaction(function () use ($user, $pending, $data): void {
                $previous = $user->email;

                $user->forceFill([
                    'email' => $pending,
                    'email_verified_at' => $this->clock->now(),
                    'pending_email' => null,
                    'previous_email' => $previous,
                    'email_changed_at' => $this->clock->now(),
                ])->save();

                $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::EmailChanged, $user->id, null, $data->ipAddress, $data->userAgent));
                $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::EmailChanged, $user->id, ['new_email' => $pending]));
            });
        } catch (UniqueConstraintViolationException) {
            // Someone took the address while the link waited.
            // The failed save left the model changed, so clear the request on the row itself.
            User::query()->whereKey($user->id)->update(['pending_email' => null]);

            throw ValidationException::withMessages(['email' => 'That address is now used by another account.']);
        }

        return true;
    }
}
