<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * How a sign-in with a provider ended.
 */
enum SocialSignInOutcome: string
{
    /** Signed in to an account already linked to this provider account. */
    case Authenticated = 'authenticated';

    /** A new account was created and the person is signed in. */
    case Created = 'created';

    /** The person is who they say, but a second factor is still owed. */
    case TwoFactorRequired = 'two_factor_required';

    /** The provider did not give an email it has verified, so no account can be found or made. */
    case EmailNotVerified = 'email_not_verified';

    /** The verified email already belongs to an account that is not linked to this provider account. Nothing was linked. */
    case AccountExists = 'account_exists';

    /** The linked account is deactivated. */
    case Refused = 'refused';
}
