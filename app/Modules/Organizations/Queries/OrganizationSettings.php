<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Models\Organization;

/**
 * The organization's own settings, with the version a form must send back when it saves.
 */
final class OrganizationSettings
{
    /**
     * @return array{name: string, slug: string, version: int, requireMfa: bool, idleTimeoutMinutes: int|null, allowedEmailDomains: list<string>}
     */
    public function for(string $organizationId): array
    {
        $organization = Organization::query()->findOrFail($organizationId);

        return [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'version' => $organization->version,
            'requireMfa' => $organization->require_mfa,
            'idleTimeoutMinutes' => $organization->session_idle_timeout_minutes,
            'allowedEmailDomains' => $organization->allowed_email_domains,
        ];
    }
}
