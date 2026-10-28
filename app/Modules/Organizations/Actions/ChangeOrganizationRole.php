<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\ChangeOrganizationRoleData;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a member an Org Owner or an ordinary Member. The organization always keeps at least one Org Owner, even when two
 * owners are demoted at the same moment: every owner row is locked while the count is taken. Nobody changes their own role.
 */
final class ChangeOrganizationRole
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws LastOwner
     * @throws ValidationException
     */
    public function handle(Actor $actor, ChangeOrganizationRoleData $data): void
    {
        if (! $actor->can(Capability::OrgMembersManage, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, function () use ($actor, $data): void {
            DB::transaction(function () use ($actor, $data): void {
                $owners = OrganizationMembership::query()->where('role', OrgRole::Owner->value)->lockForUpdate()->get();
                $membership = OrganizationMembership::query()->whereKey($data->membershipId)->lockForUpdate()->firstOrFail();

                if ($membership->user_id === $actor->id()) {
                    throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
                }

                if ($membership->role === $data->role) {
                    return;
                }

                if ($membership->role === OrgRole::Owner && $data->role !== OrgRole::Owner && $owners->count() <= 1) {
                    throw new LastOwner;
                }

                $before = $membership->role;
                $membership->forceFill(['role' => $data->role])->save();

                $this->audit->record($actor, 'org_member.role_changed', new AuditTarget('org_member', $membership->user_id), [
                    'before' => $before->value,
                    'after' => $data->role->value,
                ]);
            });
        });
    }
}
