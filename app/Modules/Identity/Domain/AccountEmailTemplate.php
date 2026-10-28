<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The account and security emails this module asks for. The value travels in the outbox payload, so it is stored and
 * must not change. Invitations have their own email, sent by the Organizations module.
 */
enum AccountEmailTemplate: string
{
    case VerifyEmail = 'verify_email';
    case SignupAttemptExisting = 'signup_attempt_existing';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case NewSignIn = 'new_signin';
    case RecoveryCodeUsed = 'recovery_code_used';
    case MfaEnabled = 'mfa_enabled';
    case MfaDisabled = 'mfa_disabled';
    case AccountLocked = 'account_locked';
    case ConfirmNewEmail = 'confirm_new_email';
    case EmailChanged = 'email_changed';
}
