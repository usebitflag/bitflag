<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * What the security log records. The log is for investigating account takeover and abuse; it is not the customer audit.
 */
enum SecurityEventType: string
{
    case SignInSucceeded = 'signin.succeeded';
    case SignInFailed = 'signin.failed';
    case SignInLocked = 'signin.locked';
    case MfaChallengeFailed = 'mfa.challenge_failed';
    case MfaRecoveryCodeUsed = 'mfa.recovery_code_used';
    case MfaEnabled = 'mfa.enabled';
    case MfaDisabled = 'mfa.disabled';
    case PasswordReset = 'password.reset';
    case PasswordChanged = 'password.changed';
    case EmailChanged = 'email.changed';
    case SessionRevoked = 'session.revoked';
    case SignedUpWithProvider = 'signup.provider';
}
