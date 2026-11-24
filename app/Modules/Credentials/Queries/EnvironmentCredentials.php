<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Queries;

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Identity\Queries\PersonLookup;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;

/**
 * The keys of one environment, as the Keys tab shows them, and the count of working keys per environment for the list.
 * Never includes a secret: a key is shown as its prefix and last four characters. Runs inside the organization's tenant
 * context.
 */
final class EnvironmentCredentials
{
    public function __construct(private readonly PersonLookup $people) {}

    /**
     * @return list<array{id: string, type: string, label: string, masked: string, prefix: string, last4: string, scopes: list<string>, createdAt: int, createdBy: array{name: string|null, formerMember: bool}, lastUsedAt: int|null, lastUsedClient: string|null, expiresAt: int|null, state: string, overlapEndsAt: int|null}>
     */
    public function for(string $environmentId): array
    {
        $credentials = Credential::query()
            ->where('environment_id', $environmentId)
            ->orderByRaw("case status when 'active' then 0 when 'rotating' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->get();

        $creators = array_values(array_unique(array_filter($credentials->map(static fn (Credential $credential): ?string => $credential->created_by)->all())));
        $profiles = $this->people->profiles($creators);

        return array_values($credentials->map(function (Credential $credential) use ($profiles): array {
            $profile = $credential->created_by === null ? null : ($profiles[$credential->created_by] ?? null);

            return [
                'id' => PublicId::encode(IdPrefix::Key, $credential->id),
                'type' => $credential->type->value,
                'label' => $credential->label,
                'masked' => $credential->display_prefix.'••••'.$credential->last4,
                'prefix' => $credential->display_prefix,
                'last4' => $credential->last4,
                'scopes' => $credential->scopes,
                'createdAt' => $credential->created_at?->getTimestamp() ?? 0,
                'createdBy' => ['name' => $profile['name'] ?? null, 'formerMember' => $profile === null],
                'lastUsedAt' => $credential->last_used_at?->getTimestamp(),
                'lastUsedClient' => $credential->last_used_client,
                'expiresAt' => $credential->expires_at?->getTimestamp(),
                'state' => $credential->status->value,
                'overlapEndsAt' => $credential->overlap_ends_at?->getTimestamp(),
            ];
        })->all());
    }

    /**
     * Working SDK and management keys (active or rotating) by environment ID, for the environments of one project.
     *
     * @return array<string, int>
     */
    public function workingCounts(string $projectId): array
    {
        $counts = [];

        $rows = Credential::query()
            ->where('project_id', $projectId)
            ->where('type', '!=', CredentialType::Personal->value)
            ->whereIn('status', [CredentialStatus::Active->value, CredentialStatus::Rotating->value])
            ->get(['environment_id']);

        foreach ($rows as $row) {
            if ($row->environment_id !== null) {
                $counts[$row->environment_id] = ($counts[$row->environment_id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The IDs of the environment's keys that still authenticate (active or rotating).
     *
     * @return list<string>
     */
    public function workingIds(string $environmentId): array
    {
        return array_values(Credential::query()
            ->where('environment_id', $environmentId)
            ->whereIn('status', [CredentialStatus::Active->value, CredentialStatus::Rotating->value])
            ->get(['id'])
            ->map(static fn (Credential $credential): string => $credential->id)
            ->all());
    }

    /**
     * Whether the credential belongs to the environment.
     */
    public function inEnvironment(string $environmentId, string $credentialId): bool
    {
        return Credential::query()->whereKey($credentialId)->where('environment_id', $environmentId)->exists();
    }

    /**
     * Whether the environment has any credential row, of any state. A history of keys keeps an environment from being deleted.
     */
    public function hasAny(string $environmentId): bool
    {
        return Credential::query()->where('environment_id', $environmentId)->exists();
    }
}
