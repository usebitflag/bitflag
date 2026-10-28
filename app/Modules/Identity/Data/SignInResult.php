<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Domain\SignInOutcome;
use App\Modules\Identity\Models\User;

/**
 * The person is set only when the password was right (`Authenticated` or `TwoFactorRequired`).
 */
final readonly class SignInResult
{
    private function __construct(public SignInOutcome $outcome, public ?User $user = null) {}

    public static function authenticated(User $user): self
    {
        return new self(SignInOutcome::Authenticated, $user);
    }

    public static function twoFactorRequired(User $user): self
    {
        return new self(SignInOutcome::TwoFactorRequired, $user);
    }

    public static function failed(): self
    {
        return new self(SignInOutcome::Failed);
    }

    public static function locked(): self
    {
        return new self(SignInOutcome::Locked);
    }
}
