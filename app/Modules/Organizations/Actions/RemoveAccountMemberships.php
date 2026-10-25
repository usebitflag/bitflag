<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Events\MemberRemovedFromOrganization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Organizations\Queries\SoleOwnerships;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Takes a person out of every organization and project, when their account is deleted. It refuses while they are the only
 * Org Owner of an organization, because that organization would be left with nobody in charge. Call it inside the
 * transaction that deletes the account: the events that tell other modules go out once that commits.
 */
final class RemoveAccountMemberships
{
    public function __construct(
        private readonly SoleOwnerships $sole,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws LastOwner
     */
    public function handle(Actor $actor, string $userId): void
    {
        $blocking = $this->sole->organizationNames($userId);

        if ($blocking !== []) {
            throw new LastOwner('You are the only owner of '.implode(', ', $blocking).'. Make someone else an owner, or delete the organization, first.');
        }

        $removed = [];

        foreach ($this->sole->organizationIds($userId) as $organizationId) {
            $this->tenant->run($organizationId, function () use ($actor, $userId, $organizationId, &$removed): void {
                // Locked while counted again, so two owners deleting their accounts together cannot empty the organization.
                $owners = OrganizationMembership::query()->where('role', OrgRole::Owner->value)->lockForUpdate()->get();
                $membership = OrganizationMembership::query()->where('user_id', $userId)->first();

                if ($membership === null) {
                    return;
                }

                if ($membership->role === OrgRole::Owner && $owners->count() <= 1) {
                    throw new LastOwner;
                }

                ProjectMembership::query()->where('user_id', $userId)->delete();
                $membership->delete();

                $this->audit->record($actor, 'org_member.removed', new AuditTarget('org_member', $userId, $actor->displayName()), ['role' => $membership->role->value, 'account_deleted' => true]);
                $removed[] = $organizationId;
            });
        }

        DB::afterCommit(function () use ($removed, $userId): void {
            foreach ($removed as $organizationId) {
                event(new MemberRemovedFromOrganization($organizationId, $userId));
            }
        });
    }
}
