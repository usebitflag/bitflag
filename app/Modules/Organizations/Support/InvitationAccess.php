<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Support;

use App\Modules\Organizations\Models\Invitation;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;

/**
 * Who may resend or withdraw an invitation: whoever manages the organization's members, or, for an invitation that only
 * adds people to projects, whoever manages every one of those projects' members.
 */
final class InvitationAccess
{
    /**
     * @throws AuthorizationDenied
     */
    public function assertCanManage(Actor $actor, Invitation $invitation): void
    {
        if ($actor->can(Capability::OrgMembersManage, new OrganizationScope($invitation->organization_id))) {
            return;
        }

        $projectIds = $invitation->grants()->get()->map(static fn ($grant): string => $grant->project_id)->all();

        if ($projectIds === []) {
            throw new AuthorizationDenied;
        }

        foreach ($projectIds as $projectId) {
            if (! $actor->can(Capability::MembersManage, new ProjectScope($invitation->organization_id, $projectId))) {
                throw new AuthorizationDenied;
            }
        }
    }
}
