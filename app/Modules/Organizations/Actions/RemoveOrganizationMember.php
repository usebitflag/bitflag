<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\RemoveOrganizationMemberData;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Events\MemberRemovedFromOrganization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
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
 * Removes a person from an organization and from every project in it. The last Org Owner cannot be removed, and nobody
 * removes themselves here. Once the removal is committed, `MemberRemovedFromOrganization` lets other modules revoke what
 * depended on belonging.
 */
final class RemoveOrganizationMember
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
    public function handle(Actor $actor, RemoveOrganizationMemberData $data): void
    {
        if (! $actor->can(Capability::OrgMembersManage, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        $removed = $this->tenant->run($data->organizationId, fn (): string => DB::transaction(function () use ($actor, $data): string {
            $owners = OrganizationMembership::query()->where('role', OrgRole::Owner->value)->lockForUpdate()->get();
            $membership = OrganizationMembership::query()->whereKey($data->membershipId)->lockForUpdate()->firstOrFail();

            if ($membership->user_id === $actor->id()) {
                throw ValidationException::withMessages(['member' => 'You cannot remove yourself here.']);
            }

            if ($membership->role === OrgRole::Owner && $owners->count() <= 1) {
                throw new LastOwner;
            }

            ProjectMembership::query()->where('user_id', $membership->user_id)->delete();
            $membership->delete();

            $this->audit->record($actor, 'org_member.removed', new AuditTarget('org_member', $membership->user_id), ['role' => $membership->role->value]);

            return $membership->user_id;
        }));

        event(new MemberRemovedFromOrganization($data->organizationId, $removed));
    }
}
