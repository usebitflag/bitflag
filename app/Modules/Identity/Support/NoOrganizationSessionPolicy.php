<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\SessionPolicyLookup;

/**
 * Used until organizations can set an idle limit.
 */
final class NoOrganizationSessionPolicy implements SessionPolicyLookup
{
    public function idleMinutesFor(string $organizationSlug): ?int
    {
        return null;
    }
}
