<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Models\User;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Psr\Clock\ClockInterface;

/**
 * Makes the link an email carries, at send time, so a secret never sits in the outbox or in a queued job. The pages the
 * links open are named routes that the sign-up and password features register.
 */
final class AccountEmailLinks
{
    public const VERIFICATION_HOURS = 24;

    public function __construct(private readonly ClockInterface $clock) {}

    public function for(AccountEmailTemplate $template, User $user): string
    {
        return match ($template) {
            AccountEmailTemplate::VerifyEmail => URL::temporarySignedRoute(
                'verification.verify',
                $this->clock->now()->modify('+'.self::VERIFICATION_HOURS.' hours'),
                ['id' => $user->id, 'hash' => sha1($user->email)],
            ),
            AccountEmailTemplate::PasswordReset => route('password.reset', ['token' => Password::broker()->createToken($user), 'user' => PublicId::encode(IdPrefix::User, $user->id)]),
            AccountEmailTemplate::SignupAttemptExisting => url('/signin'),
            AccountEmailTemplate::PasswordChanged, AccountEmailTemplate::AccountLocked => url('/forgot-password'),
            AccountEmailTemplate::NewSignIn,
            AccountEmailTemplate::RecoveryCodeUsed,
            AccountEmailTemplate::MfaEnabled,
            AccountEmailTemplate::MfaDisabled => url('/account/security'),
        };
    }
}
