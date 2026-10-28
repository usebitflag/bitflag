<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\SignInThrottle;
use App\Modules\Identity\Models\User;
use App\Shared\Mail\EmailMessage;
use App\Shared\Mail\MailTime;
use DateTimeImmutable;

/**
 * The words of each account and security email: the subject, the preview line, the heading and the facts. The layout is
 * shared (see `EmailMessage`). Facts that were not known when the email was asked for, such as a place, are left out
 * rather than guessed.
 */
final class AccountEmailContent
{
    /**
     * @param  array<array-key, mixed>  $details  Short non-secret facts the request carried (device, place, codes left).
     */
    public function for(AccountEmailTemplate $template, User $user, string $link, array $details, string $recipient, DateTimeImmutable $now): EmailMessage
    {
        $when = ['label' => 'When', 'value' => MailTime::format($now, $user->timezone ?? 'UTC')];
        $device = $this->fact('Device', $details['device_label'] ?? null);
        $place = $this->fact('Location', isset($details['location']) && is_string($details['location']) ? $details['location'].' (approximate)' : null);
        $greeting = 'Hi '.$this->firstName($user->name).',';
        $deviceName = is_string($details['device_label'] ?? null) ? $details['device_label'] : 'a new device';
        $codesLeft = is_int($details['codes_left'] ?? null) ? $details['codes_left'] : null;

        $message = match ($template) {
            AccountEmailTemplate::VerifyEmail => new EmailMessage(
                'Verify your email for BitFlag',
                'Confirm this address to finish setting up your account.',
                EmailMessage::KICKER_ACCOUNT,
                'Confirm your email',
                $greeting,
                ['Confirm that this is your email address to finish setting up BitFlag.'],
                panel: ['heading' => 'Unlocks after you confirm', 'items' => ['Inviting people', 'Creating SDK keys', 'Publishing']],
                button: ['label' => 'Verify email', 'url' => $link],
                note: "This link expires in 24 hours. If you didn't create a BitFlag account, ignore this email and nothing will happen.",
            ),
            AccountEmailTemplate::SignupAttemptExisting => new EmailMessage(
                'Someone tried to create a BitFlag account with your email',
                'You already have an account. No new account was created.',
                EmailMessage::KICKER_ACCOUNT,
                'Someone tried to sign up as you',
                $greeting,
                [
                    'Someone tried to create a new BitFlag account with this email address. You already have an account, so nothing was created.',
                    'If it was you, sign in instead. If you forgot your password, you can reset it.',
                ],
                button: ['label' => 'Sign in', 'url' => $link],
                callout: ['tone' => 'info', 'text' => "If this wasn't you, you don't need to do anything. Your account hasn't changed."],
            ),
            AccountEmailTemplate::PasswordReset => new EmailMessage(
                'Reset your BitFlag password',
                'Use this link to choose a new password.',
                EmailMessage::KICKER_ACCOUNT,
                'Choose a new password',
                $greeting,
                ['We got a request to reset the password for your BitFlag account.'],
                array_values(array_filter([['label' => 'Requested', 'value' => $when['value']], $device, $place])),
                button: ['label' => 'Choose a new password', 'url' => $link],
                note: "This link works once and expires in 1 hour. Resetting your password signs you out everywhere else. If you didn't ask for this, ignore this email; your password stays the same.",
            ),
            AccountEmailTemplate::PasswordChanged => new EmailMessage(
                'Your BitFlag password was changed',
                "If this was you, there's nothing to do.",
                EmailMessage::KICKER_SECURITY,
                'Your password was changed',
                $greeting,
                ['The password for your BitFlag account was just changed, and your other sessions were signed out.'],
                array_values(array_filter([$when, $device, $place])),
                button: ['label' => 'Reset password', 'url' => $link],
                callout: ['tone' => 'warning', 'text' => "Wasn't you? Reset your password now and check your active sessions."],
                reason: $this->securityReason(),
            ),
            AccountEmailTemplate::ConfirmNewEmail => new EmailMessage(
                'Confirm your new email for BitFlag',
                'Confirm this address to finish the change.',
                EmailMessage::KICKER_ACCOUNT,
                'Confirm your new email',
                $greeting,
                ['You asked to change the email on your BitFlag account.'],
                [['label' => 'From', 'value' => $user->email], ['label' => 'To', 'value' => $recipient]],
                button: ['label' => 'Confirm new email', 'url' => $link],
                note: 'This link expires in 24 hours. Until you confirm, BitFlag keeps using your old address.',
            ),
            AccountEmailTemplate::EmailChanged => new EmailMessage(
                'The email on your BitFlag account was changed',
                'Your account now uses a different address.',
                EmailMessage::KICKER_SECURITY,
                'Your account email changed',
                $greeting,
                ["The email on your BitFlag account was changed. You won't get account emails at this address anymore."],
                [
                    ['label' => 'New address', 'value' => $this->maskEmail(is_string($details['new_email'] ?? null) ? $details['new_email'] : '')],
                    $when,
                ],
                button: ['label' => 'Reset password', 'url' => $link],
                callout: ['tone' => 'warning', 'text' => "Wasn't you? Reset your password right away and check your sessions."],
                reason: $this->securityReason(),
            ),
            AccountEmailTemplate::NewSignIn => new EmailMessage(
                'New sign-in to BitFlag from '.$deviceName,
                "A device we haven't seen before signed in.",
                EmailMessage::KICKER_SECURITY,
                'New sign-in from '.$deviceName,
                $greeting,
                ["Your BitFlag account was just signed in to from a device we haven't seen before. If this was you, you can ignore this email."],
                array_values(array_filter([$when, $device, $place])),
                button: ['label' => 'Review sessions', 'url' => $link],
                callout: ['tone' => 'warning', 'text' => "Wasn't you? Change your password and sign out that session from your account settings."],
                reason: $this->securityReason(),
            ),
            AccountEmailTemplate::RecoveryCodeUsed => new EmailMessage(
                'A recovery code was used to sign in to BitFlag',
                'Someone used a two-factor recovery code.',
                EmailMessage::KICKER_SECURITY,
                'A recovery code was used',
                $greeting,
                [
                    'Someone signed in to your account with one of your two-factor recovery codes instead of your authenticator app.',
                    'If you lost your authenticator, set up two-factor sign-in again and create new recovery codes.',
                ],
                array_values(array_filter([
                    $when,
                    $device,
                    $codesLeft !== null ? ['label' => 'Codes left', 'value' => $codesLeft.' of 10'] : null,
                ])),
                button: ['label' => 'Set up two-factor sign-in', 'url' => $link],
                callout: ['tone' => 'warning', 'text' => "Wasn't you? Change your password and sign out all sessions now."],
                reason: $this->securityReason(),
            ),
            AccountEmailTemplate::MfaEnabled, AccountEmailTemplate::MfaDisabled => $this->twoFactorChanged($template === AccountEmailTemplate::MfaEnabled, $greeting, $link, array_values(array_filter([$when, $device, $place]))),
            AccountEmailTemplate::AccountLocked => new EmailMessage(
                'Your BitFlag account is temporarily locked',
                'Locked for 15 minutes after failed sign-ins.',
                EmailMessage::KICKER_SECURITY,
                'Account locked for 15 minutes',
                $greeting,
                ['We locked your account after several failed sign-in attempts. If this was you, wait and try again, or reset your password.'],
                array_values(array_filter([
                    $when,
                    isset($details['location']) && is_string($details['location']) ? ['label' => 'Attempts from', 'value' => $details['location'].' (approximate)'] : null,
                    ['label' => 'Unlocks', 'value' => MailTime::format($now->modify('+'.SignInThrottle::LOCK_SECONDS.' seconds'), $user->timezone ?? 'UTC')],
                ])),
                button: ['label' => 'Reset password', 'url' => $link],
                callout: ['tone' => 'info', 'text' => "If it wasn't you, your password held. Consider turning on two-factor sign-in if you haven't already."],
                reason: $this->securityReason(),
            ),
        };

        return $message->withRecipient($recipient);
    }

    /**
     * @param  list<array{label: string, value: string}>  $facts
     */
    private function twoFactorChanged(bool $enabled, string $greeting, string $link, array $facts): EmailMessage
    {
        return new EmailMessage(
            'Two-factor sign-in is '.($enabled ? 'on' : 'off').' for BitFlag',
            'Two-factor sign-in was turned '.($enabled ? 'on' : 'off').' for your account.',
            EmailMessage::KICKER_SECURITY,
            'Two-factor sign-in is '.($enabled ? 'on' : 'off'),
            $greeting,
            ['Two-factor sign-in was turned '.($enabled ? 'on' : 'off').' for your BitFlag account.'],
            $facts,
            button: ['label' => 'Review security settings', 'url' => $link],
            callout: ['tone' => 'warning', 'text' => "Wasn't you? Change your password and sign out all sessions now."],
            reason: $this->securityReason(),
        );
    }

    private function securityReason(): string
    {
        return "You get this email because it concerns the security of your BitFlag account. It can't be turned off.";
    }

    /**
     * @return array{label: string, value: string}|null
     */
    private function fact(string $label, mixed $value): ?array
    {
        return is_string($value) && $value !== '' ? ['label' => $label, 'value' => $value] : null;
    }

    private function firstName(string $name): string
    {
        $first = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';

        return $first !== '' ? $first : 'there';
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($local === '' || $domain === '') {
            return 'a new address';
        }

        return mb_substr($local, 0, 1).str_repeat('•', max(2, min(7, mb_strlen($local) - 1))).'@'.$domain;
    }
}
