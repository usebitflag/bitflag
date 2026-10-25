<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Identity\Actions\RememberOrganizationChoice;
use App\Modules\Organizations\Data\ChooseOrganizationData;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Errors\AuthorizationDenied;

/**
 * Picks the organization to work in after signing in, and optionally remembers the choice so the chooser is skipped next
 * time. Only organizations the person can read can be chosen.
 */
final class ChooseOrganization
{
    public function __construct(private readonly RememberOrganizationChoice $remember) {}

    /**
     * @throws AuthorizationDenied
     */
    public function handle(Actor $actor, ChooseOrganizationData $data): void
    {
        $userId = $actor->id();

        if ($userId === null || ! $actor->can(Capability::OrgRead, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        $this->remember->handle($userId, $data->organizationId, $data->remember);
    }
}
