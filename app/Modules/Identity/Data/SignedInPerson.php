<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * The person behind the current portal session, with the scope they last worked in.
 */
final readonly class SignedInPerson
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $lastOrganizationId = null,
        public ?string $lastProjectId = null,
        public ?string $lastEnvironmentId = null,
        public bool $emailVerified = true,
        public bool $rememberOrgChoice = false,
        /** False for a person who signed up with a provider and has not set a password. */
        public bool $hasPassword = true,
    ) {}

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(static fn (string $part): string => mb_substr($part, 0, 1), array_slice($parts, 0, 2))));
    }
}
