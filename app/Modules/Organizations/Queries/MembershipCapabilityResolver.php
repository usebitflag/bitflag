<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Identity\Queries\UserStatus;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Actors\Actor;
use App\Shared\Actors\ActorType;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\CapabilityResolver;
use App\Shared\Capabilities\EnvironmentProtection;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\OrgRoleCapabilityMap;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Capabilities\RoleCapabilityMap;
use App\Shared\Capabilities\Scope;
use App\Shared\Capabilities\TokenScopeMap;
use App\Shared\Tenancy\Tenancy;

/**
 * Resolves capabilities from organization and project memberships.
 *
 * - An Org Owner is not a project member, so organization capabilities never imply project capabilities.
 * - A deactivated account holds nothing.
 * - A personal token can only narrow its owner's permissions: both must allow.
 * - A management token is checked against its own scopes only.
 * - The system acts with service authority; support staff hold no customer capabilities.
 *
 * Lookups are remembered for the request or job. Call forget() after a membership changes inside the same one.
 */
final class MembershipCapabilityResolver implements CapabilityResolver
{
    /** @var array<string, OrgRole|false> */
    private array $orgRoles = [];

    /** @var array<string, ProjectRole|false> */
    private array $projectRoles = [];

    /** @var array<string, bool> */
    private array $activeUsers = [];

    public function __construct(
        private readonly UserStatus $users,
        private readonly EnvironmentProtection $protection,
        private readonly TokenScopeMap $tokens,
    ) {}

    public function can(Actor $actor, Capability $capability, Scope $scope): bool
    {
        return match ($actor->type()) {
            ActorType::System => true,
            ActorType::Support => false,
            ActorType::ManagementToken => $this->tokens->allows($actor, $capability, $scope),
            ActorType::User => $this->userCan($actor, $capability, $scope)
                && ($actor->viaTokenId() === null || $this->tokens->allows($actor, $capability, $scope)),
        };
    }

    public function forget(): void
    {
        $this->orgRoles = [];
        $this->projectRoles = [];
        $this->activeUsers = [];
    }

    private function userCan(Actor $actor, Capability $capability, Scope $scope): bool
    {
        $userId = $actor->id();

        if ($userId === null || ! $this->isActive($userId)) {
            return false;
        }

        if ($capability->isOrganizationLevel()) {
            $role = $this->orgRole($userId, $scope->organizationId());

            return $role !== null && OrgRoleCapabilityMap::allows($role, $capability);
        }

        if (! $scope instanceof ProjectScope && ! $scope instanceof EnvironmentScope) {
            return false;
        }

        $role = $this->projectRole($userId, $scope->organizationId(), $scope->projectId);

        if ($role === null) {
            return false;
        }

        // Publishing to a protected environment needs the protected-publish capability instead.
        if ($capability === Capability::EnvironmentPublish && $scope instanceof EnvironmentScope && $this->protection->isProtected($scope)) {
            $capability = Capability::EnvironmentPublishProtected;
        }

        return RoleCapabilityMap::allows($role, $capability);
    }

    private function isActive(string $userId): bool
    {
        return $this->activeUsers[$userId] ??= $this->users->isActive($userId);
    }

    private function orgRole(string $userId, string $organizationId): ?OrgRole
    {
        $key = "{$organizationId}:{$userId}";

        $this->orgRoles[$key] ??= Tenancy::crossTenant('membership lookup resolves the capability scope', function () use ($userId, $organizationId): OrgRole|false {
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->first();

            return $membership === null ? false : $membership->role;
        });

        return $this->orgRoles[$key] ?: null;
    }

    private function projectRole(string $userId, string $organizationId, string $projectId): ?ProjectRole
    {
        $key = "{$organizationId}:{$projectId}:{$userId}";

        $this->projectRoles[$key] ??= Tenancy::crossTenant('membership lookup resolves the capability scope', function () use ($userId, $organizationId, $projectId): ProjectRole|false {
            $membership = ProjectMembership::query()
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('user_id', $userId)
                ->first();

            return $membership === null ? false : $membership->role;
        });

        return $this->projectRoles[$key] ?: null;
    }
}
