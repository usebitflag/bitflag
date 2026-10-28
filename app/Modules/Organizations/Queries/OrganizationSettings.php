<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Models\Organization;
use App\Shared\Plans\BillingStatus;

/**
 * The organization's own settings, with the version a form must send back when it saves.
 */
final class OrganizationSettings
{
    public function __construct(private readonly BillingStatus $billing) {}

    /**
     * @return array{name: string, slug: string, status: string, deletionDueAt: int|null, paidSubscription: bool, version: int, requireMfa: bool, idleTimeoutMinutes: int|null, allowedEmailDomains: list<string>}
     */
    public function for(string $organizationId): array
    {
        $organization = Organization::query()->findOrFail($organizationId);

        return [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status->value,
            'deletionDueAt' => $organization->deletion_due_at?->getTimestamp(),
            'paidSubscription' => $this->billing->hasActivePaidSubscription($organization->id),
            'version' => $organization->version,
            'requireMfa' => $organization->require_mfa,
            'idleTimeoutMinutes' => $organization->session_idle_timeout_minutes,
            'allowedEmailDomains' => $organization->allowed_email_domains,
        ];
    }
}
