<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Queries;

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Models\Credential;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Tenancy\Tenancy;

/**
 * Personal access tokens: a person's own tokens for a project or across all their projects, and the ones that must be revoked
 * when they leave. Never includes a secret.
 */
final class PersonalTokens
{
    /**
     * @return list<array{id: string, label: string, masked: string, scopes: list<string>, access: string, createdAt: int, lastUsedAt: int|null, expiresAt: int|null, state: string}>
     */
    public function forUserInProject(string $userId, string $projectId): array
    {
        return array_values(Credential::query()
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('type', CredentialType::Personal->value)
            ->orderByRaw("case status when 'active' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Credential $credential): array => $this->row($credential))
            ->all());
    }

    /**
     * A person's tokens in every organization and project, with the IDs that locate each one. Used by the account page.
     *
     * @return list<array{id: string, label: string, masked: string, scopes: list<string>, access: string, createdAt: int, lastUsedAt: int|null, expiresAt: int|null, state: string, organizationId: string, projectId: string}>
     */
    public function forUserEverywhere(string $userId): array
    {
        return Tenancy::crossTenant('a person lists their own personal tokens across organizations', fn (): array => array_values(Credential::query()
            ->where('user_id', $userId)
            ->where('type', CredentialType::Personal->value)
            ->orderByRaw("case status when 'active' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Credential $credential): array => [...$this->row($credential), 'organizationId' => $credential->organization_id, 'projectId' => $credential->project_id])
            ->all()));
    }

    /**
     * The tokens still working that belong to a person, optionally within one organization or project, as the pairs the revoke
     * action needs.
     *
     * @return list<array{organizationId: string, credentialId: string}>
     */
    public function workingFor(string $userId, ?string $organizationId = null, ?string $projectId = null): array
    {
        return Tenancy::crossTenant('revoking a departing member\'s personal tokens finds them in every organization', fn (): array => array_values(Credential::query()
            ->where('user_id', $userId)
            ->where('type', CredentialType::Personal->value)
            ->whereIn('status', [CredentialStatus::Active->value, CredentialStatus::Rotating->value])
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->get()
            ->map(static fn (Credential $credential): array => ['organizationId' => $credential->organization_id, 'credentialId' => $credential->id])
            ->all()));
    }

    /**
     * Whether the credential is a personal token of the project. Runs inside the organization's tenant context.
     */
    public function inProject(string $projectId, string $credentialId): bool
    {
        return Credential::query()->whereKey($credentialId)->where('project_id', $projectId)->where('type', CredentialType::Personal->value)->exists();
    }

    /**
     * Whether a personal token belongs to the person, found without a tenant context (the account page knows no organization).
     */
    public function ownedBy(string $userId, string $credentialId): ?string
    {
        return Tenancy::crossTenant('a person revokes their own token from the account page', function () use ($userId, $credentialId): ?string {
            $credential = Credential::query()->whereKey($credentialId)->where('user_id', $userId)->where('type', CredentialType::Personal->value)->first();

            return $credential?->organization_id;
        });
    }

    /**
     * The project's management keys across its environments, read-only for the API tokens page.
     *
     * @return list<array{id: string, label: string, masked: string, scopes: list<string>, environmentId: string|null, state: string, createdAt: int, lastUsedAt: int|null, expiresAt: int|null}>
     */
    public function managementKeys(string $projectId): array
    {
        return array_values(Credential::query()
            ->where('project_id', $projectId)
            ->where('type', CredentialType::Management->value)
            ->orderByRaw("case status when 'active' then 0 when 'rotating' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (Credential $credential): array => [
                'id' => PublicId::encode(IdPrefix::Key, $credential->id),
                'label' => $credential->label,
                'masked' => $credential->display_prefix.'••••'.$credential->last4,
                'scopes' => $credential->scopes,
                'environmentId' => $credential->environment_id,
                'state' => $credential->status->value,
                'createdAt' => $credential->created_at?->getTimestamp() ?? 0,
                'lastUsedAt' => $credential->last_used_at?->getTimestamp(),
                'expiresAt' => $credential->expires_at?->getTimestamp(),
            ])
            ->all());
    }

    /**
     * @return array{id: string, label: string, masked: string, scopes: list<string>, access: string, createdAt: int, lastUsedAt: int|null, expiresAt: int|null, state: string}
     */
    private function row(Credential $credential): array
    {
        $scopes = $credential->scopes;

        return [
            'id' => PublicId::encode(IdPrefix::Key, $credential->id),
            'label' => $credential->label,
            'masked' => $credential->display_prefix.'••••'.$credential->last4,
            'scopes' => $scopes,
            'access' => in_array('publish', $scopes, true) ? 'publish' : (in_array('flags:write', $scopes, true) ? 'draft' : 'read'),
            'createdAt' => $credential->created_at?->getTimestamp() ?? 0,
            'lastUsedAt' => $credential->last_used_at?->getTimestamp(),
            'expiresAt' => $credential->expires_at?->getTimestamp(),
            'state' => $credential->status->value,
        ];
    }
}
