<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Queries;

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Models\Credential;
use App\Shared\Tenancy\Tenancy;

/**
 * How many working credentials were hashed under each pepper, so an old pepper is retired only when nothing uses it.
 */
final class PepperUsage
{
    /**
     * @return array<int, int> pepper id => working credentials
     */
    public function counts(): array
    {
        $counts = [];

        Tenancy::crossTenant('pepper usage counts credentials in every organization', function () use (&$counts): void {
            foreach (Credential::query()->whereIn('status', [CredentialStatus::Active->value, CredentialStatus::Rotating->value])->get(['pepper_id']) as $credential) {
                $counts[$credential->pepper_id] = ($counts[$credential->pepper_id] ?? 0) + 1;
            }
        });

        ksort($counts);

        return $counts;
    }
}
