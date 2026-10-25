<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * Keeps which organization a person last chose, and whether to skip the chooser next time. Moving to another organization
 * forgets the project and environment remembered for the old one. A preference, so it is not audited. The caller has
 * already checked the person belongs to the organization.
 */
final class RememberOrganizationChoice
{
    public function handle(string $userId, string $organizationId, bool $remember): void
    {
        $user = User::query()->findOrFail($userId);
        $changed = $user->last_organization_id !== $organizationId;

        $user->forceFill([
            'last_organization_id' => $organizationId,
            'last_project_id' => $changed ? null : $user->last_project_id,
            'last_environment_id' => $changed ? null : $user->last_environment_id,
            'remember_org_choice' => $remember,
        ])->save();
    }
}
