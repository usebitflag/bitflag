<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * What makes a password acceptable, shared by sign-up, reset, change and invitation accept. There are no composition
 * rules (digits, symbols): length, not being common and not being the person's own email are what matter.
 */
final class PasswordRules
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 128;

    public const TOO_SHORT = 'At least 12 characters';

    public const TOO_LONG = 'At most 128 characters';

    public const COMMON = 'Not a common password';

    public const IS_EMAIL = 'Not your email';

    public function __construct(private readonly CommonPasswords $common) {}

    /**
     * The messages of the rules the password breaks, in a stable order. Empty means acceptable.
     *
     * @return list<string>
     */
    public function violations(string $password, ?string $email = null): array
    {
        $violations = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $violations[] = self::TOO_SHORT;
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = self::TOO_LONG;
        }

        if ($this->common->contains($password)) {
            $violations[] = self::COMMON;
        }

        if ($email !== null && $this->isEmail($password, $email)) {
            $violations[] = self::IS_EMAIL;
        }

        return $violations;
    }

    private function isEmail(string $password, string $email): bool
    {
        $candidate = mb_strtolower(trim($password));
        $email = mb_strtolower(trim($email));
        $local = explode('@', $email)[0];

        return $candidate === $email || ($local !== '' && $candidate === $local);
    }
}
