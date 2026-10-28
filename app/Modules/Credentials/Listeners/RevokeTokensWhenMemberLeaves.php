<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Listeners;

use App\Modules\Credentials\Actions\RevokeTokensForMember;
use App\Modules\Credentials\Domain\RevokeReason;
use App\Modules\Organizations\Events\MemberRemovedFromOrganization;
use App\Modules\Organizations\Events\MemberRemovedFromProject;

/**
 * A person who leaves a project loses their personal tokens for it; one who leaves an organization loses all their tokens in it.
 */
final class RevokeTokensWhenMemberLeaves
{
    public function __construct(private readonly RevokeTokensForMember $revoke) {}

    public function handleProject(MemberRemovedFromProject $event): void
    {
        $this->revoke->handle($event->userId, RevokeReason::MemberRemoved, $event->organizationId, $event->projectId);
    }

    public function handleOrganization(MemberRemovedFromOrganization $event): void
    {
        $this->revoke->handle($event->userId, RevokeReason::MemberRemoved, $event->organizationId);
    }
}
