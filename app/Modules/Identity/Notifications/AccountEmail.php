<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Domain\AccountEmailTemplate;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An account or security email as plain text. The wording is interim; the email catalog replaces it without changing the
 * outbox contract.
 */
final class AccountEmail extends Notification
{
    /**
     * @param  array<array-key, mixed>  $details
     */
    public function __construct(
        private readonly AccountEmailTemplate $template,
        private readonly string $link,
        private readonly array $details = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $body] = $this->content();

        return (new MailMessage)->subject($subject)->text('mail.account', ['body' => $body, 'link' => $this->link]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function content(): array
    {
        return match ($this->template) {
            AccountEmailTemplate::VerifyEmail => [
                'Confirm your email for BitFlag',
                'Confirm your email address to finish setting up your account. The link works for 24 hours.',
            ],
            AccountEmailTemplate::SignupAttemptExisting => [
                'You already have a BitFlag account',
                'Someone tried to create a BitFlag account with this email address, but it already has one. If that was you, sign in instead; if you forgot your password, you can reset it from the sign-in page. If it was not you, you can ignore this email.',
            ],
            AccountEmailTemplate::PasswordReset => [
                'Reset your BitFlag password',
                'Use the link to choose a new password. It works for 60 minutes. If you did not ask for this, ignore this email; your password has not changed.',
            ],
            AccountEmailTemplate::PasswordChanged => [
                'Your BitFlag password was changed',
                'Your password was just changed and your other sessions were signed out. If this was not you, reset your password now and contact support.',
            ],
            AccountEmailTemplate::NewSignIn => [
                'New sign-in to BitFlag',
                'We saw a sign-in from a device we have not seen before: '.$this->detail('device_label', 'an unknown device').' near '.$this->detail('location', 'an unknown location').'. If this was not you, change your password and review your sessions.',
            ],
            AccountEmailTemplate::RecoveryCodeUsed => [
                'A BitFlag recovery code was used',
                'A recovery code was used to sign in to your account. You have '.$this->detail('codes_left', 'few').' recovery codes left. If this was not you, change your password and generate new codes.',
            ],
            AccountEmailTemplate::MfaEnabled => [
                'Two-factor sign-in is on for BitFlag',
                'Two-factor sign-in was turned on for your account. If this was not you, secure your account now.',
            ],
            AccountEmailTemplate::MfaDisabled => [
                'Two-factor sign-in is off for BitFlag',
                'Two-factor sign-in was turned off for your account. If this was not you, secure your account now.',
            ],
            AccountEmailTemplate::ConfirmNewEmail => [
                'Confirm your new email for BitFlag',
                'Confirm this address to use it for your BitFlag account. The link works for 24 hours. Until you confirm, your account keeps its current address.',
            ],
            AccountEmailTemplate::EmailChanged => [
                'Your BitFlag email address was changed',
                'The email address of your BitFlag account was just changed to '.$this->detail('new_email', 'a new address').'. If this was not you, reset your password now and contact support.',
            ],
            AccountEmailTemplate::AccountLocked => [
                'Too many sign-in attempts on BitFlag',
                'Sign-in to your account is paused for 15 minutes after too many failed attempts. If this was not you, reset your password.',
            ],
        };
    }

    private function detail(string $key, string $fallback): string
    {
        $value = $this->details[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : $fallback;
    }
}
