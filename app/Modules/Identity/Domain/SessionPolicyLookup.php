<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The idle limit an organization has chosen for the people who open it, in minutes, or null for the platform's.
 */
interface SessionPolicyLookup
{
    public function idleMinutesFor(string $organizationSlug): ?int;
}
