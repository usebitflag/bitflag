<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Domain\CommonPasswords;

/**
 * Answers password questions for the screens that ask while a person types. The full rules are enforced where a password
 * is set; this only reports whether it is on the local common list.
 */
final class PasswordStrength
{
    public function __construct(private readonly CommonPasswords $common) {}

    public function isCommon(string $password): bool
    {
        return $this->common->contains($password);
    }
}
