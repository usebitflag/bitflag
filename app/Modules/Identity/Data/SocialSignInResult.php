<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Models\User;

/**
 * The person is set when they were found or created, and the device token only when they are signed in with nothing more owed.
 */
final readonly class SocialSignInResult
{
    private function __construct(
        public SocialSignInOutcome $outcome,
        public ?User $user = null,
        public ?string $deviceToken = null,
        public ?string $email = null,
    ) {}

    public static function signedIn(SocialSignInOutcome $outcome, User $user, string $deviceToken): self
    {
        return new self($outcome, $user, $deviceToken);
    }

    public static function twoFactorRequired(User $user): self
    {
        return new self(SocialSignInOutcome::TwoFactorRequired, $user);
    }

    public static function emailNotVerified(): self
    {
        return new self(SocialSignInOutcome::EmailNotVerified);
    }

    public static function accountExists(string $email): self
    {
        return new self(SocialSignInOutcome::AccountExists, null, null, $email);
    }

    public static function refused(): self
    {
        return new self(SocialSignInOutcome::Refused);
    }
}
