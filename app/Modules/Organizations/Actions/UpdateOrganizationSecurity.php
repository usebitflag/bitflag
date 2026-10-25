<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\UpdateOrganizationSecurityData;
use App\Modules\Organizations\Domain\AllowedDomains;
use App\Modules\Organizations\Models\Organization;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Concurrency\VersionedUpdate;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes how an organization protects itself: whether members need two-factor, how long an idle session lasts, and which
 * email domains may join. Turning two-factor on signs nobody out; people without it are sent to set it up the next time
 * they open the organization. Returns the new version.
 */
final class UpdateOrganizationSecurity
{
    /** Idle limits an organization may choose, in minutes; null is the platform's 7 days. */
    private const IDLE_OPTIONS = [60, 720, 1440];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, UpdateOrganizationSecurityData $data): int
    {
        if (! $actor->can(Capability::OrgManage, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        if ($data->idleTimeoutMinutes !== null && ! in_array($data->idleTimeoutMinutes, self::IDLE_OPTIONS, true)) {
            throw ValidationException::withMessages(['idle_timeout_minutes' => 'Choose 1 hour, 12 hours or 24 hours.']);
        }

        $cleaned = AllowedDomains::clean($data->allowedEmailDomains);

        if ($cleaned['invalid'] !== []) {
            throw ValidationException::withMessages(['allowed_email_domains' => 'These are not domains: '.implode(', ', $cleaned['invalid']).'.']);
        }

        if (count($cleaned['domains']) > AllowedDomains::MAX) {
            throw ValidationException::withMessages(['allowed_email_domains' => 'Allow up to '.AllowedDomains::MAX.' domains.']);
        }

        return DB::transaction(function () use ($actor, $data, $cleaned): int {
            $organization = Organization::query()->findOrFail($data->organizationId);
            $before = [
                'require_mfa' => $organization->require_mfa,
                'idle_timeout_minutes' => $organization->session_idle_timeout_minutes,
                'allowed_email_domains' => $organization->allowed_email_domains,
            ];
            $after = [
                'require_mfa' => $data->requireMfa,
                'idle_timeout_minutes' => $data->idleTimeoutMinutes,
                'allowed_email_domains' => $cleaned['domains'],
            ];

            $version = VersionedUpdate::apply(Organization::query()->whereKey($organization->id), $data->expectedVersion, [
                'require_mfa' => $data->requireMfa,
                'session_idle_timeout_minutes' => $data->idleTimeoutMinutes,
                'allowed_email_domains' => json_encode($cleaned['domains'], JSON_THROW_ON_ERROR),
            ]);

            $this->tenant->run($organization->id, fn () => $this->audit->record(
                $actor,
                'organization.security_changed',
                new AuditTarget('organization', $organization->id, $organization->name),
                ['before' => $before, 'after' => $after, 'version' => $version],
            ));

            return $version;
        });
    }
}
