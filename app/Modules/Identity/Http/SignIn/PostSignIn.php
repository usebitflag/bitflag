<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

/**
 * Things the session remembers about a sign-in that has just finished, for the first page after it.
 */
final class PostSignIn
{
    /** Set when a person signs in; the first visit to the site root reads it once to offer the organization chooser. */
    public const CHOOSE_ORGANIZATION = 'signin.choose_organization';
}
