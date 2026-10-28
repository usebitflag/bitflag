<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Which organizations a person belongs to that require two-factor sign-in. Such a person cannot turn two-factor off.
 */
interface OrganizationMfaPolicy
{
    /**
     * The names of the organizations that require two-factor for this person.
     *
     * @return list<string>
     */
    public function organizationsRequiringMfa(string $userId): array;
}
