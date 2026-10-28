<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Queries;

use App\Modules\Credentials\Data\TokenGrant;
use App\Modules\Credentials\Domain\CredentialScope;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Models\Credential;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Capabilities\Scope;
use App\Shared\Capabilities\TokenScopeMap;
use App\Shared\Tenancy\Tenancy;

/**
 * What a token's scopes allow. A token only narrows: the resolver also asks the person's role (for a personal token) before
 * it asks this map.
 *
 *   flags:read      project.read, audit.read
 *   sensitive:read  sensitive.read
 *   flags:write     flags.draft.write, segments.write
 *   publish         changeset.submit, environment.publish, environment.publish.protected (a protected environment still needs
 *                   a review before anything is published), and changeset.approve for personal tokens only
 *
 * Everything else, such as managing the project, members, environments, credentials, integrations and experiments, is never
 * done through a token. A token reaches only its own organization and project, and a management key acts on environment-level
 * data only in its own environment.
 */
final class ScopeTokenScopeMap implements TokenScopeMap
{
    /** @var array<string, TokenGrant|false> */
    private array $grants = [];

    public function allows(Actor $actor, Capability $capability, Scope $scope): bool
    {
        $tokenId = $actor->viaTokenId();

        if ($tokenId === null) {
            return false;
        }

        $grant = $this->grant($tokenId);

        if ($grant === null || ! $this->reaches($grant, $scope)) {
            return false;
        }

        return in_array($capability, $this->capabilities($grant), true);
    }

    public function grant(string $tokenId): ?TokenGrant
    {
        $this->grants[$tokenId] ??= Tenancy::crossTenant('a token\'s scopes are read to decide what it may do', function () use ($tokenId): TokenGrant|false {
            $credential = Credential::query()->find($tokenId);

            if ($credential === null || ! in_array($credential->type, [CredentialType::Management, CredentialType::Personal], true)) {
                return false;
            }

            return new TokenGrant($credential->type, $credential->organization_id, $credential->project_id, $credential->environment_id, $credential->scopes);
        });

        return $this->grants[$tokenId] === false ? null : $this->grants[$tokenId];
    }

    private function reaches(TokenGrant $grant, Scope $scope): bool
    {
        if (! $scope instanceof ProjectScope && ! $scope instanceof EnvironmentScope) {
            return false;
        }

        if ($scope->organizationId() !== $grant->organizationId || $scope->projectId !== $grant->projectId) {
            return false;
        }

        // A management key acts on an environment's data only in its own environment.
        return ! ($scope instanceof EnvironmentScope && $grant->type === CredentialType::Management && $scope->environmentId !== $grant->environmentId);
    }

    /**
     * @return list<Capability>
     */
    private function capabilities(TokenGrant $grant): array
    {
        $capabilities = [];

        foreach ($grant->scopes as $name) {
            $scope = CredentialScope::tryFrom($name);

            $capabilities = [...$capabilities, ...match ($scope) {
                CredentialScope::FlagsRead => [Capability::ProjectRead, Capability::AuditRead],
                CredentialScope::SensitiveRead => [Capability::SensitiveRead],
                CredentialScope::FlagsWrite => [Capability::FlagsDraftWrite, Capability::SegmentsWrite],
                CredentialScope::Publish => [
                    Capability::ChangesetSubmit,
                    Capability::EnvironmentPublish,
                    Capability::EnvironmentPublishProtected,
                    ...($grant->type === CredentialType::Personal ? [Capability::ChangesetApprove] : []),
                ],
                default => [],
            }];
        }

        return $capabilities;
    }
}
