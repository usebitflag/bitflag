<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Models\Credential;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * What every change to a credential's state does once it commits: write the new summary over the cache entry, and leave the
 * outbox message that repeats the write from MySQL if this process dies first. Call `afterChange` inside the transaction that
 * changed the credential; the cache is written after the commit.
 */
final class CredentialState
{
    public function __construct(
        private readonly CredentialCache $cache,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The outbox message that asks the repair consumer to rewrite the cache entry.
     */
    public function message(Credential $credential): OutboxMessage
    {
        return OutboxMessage::forOrganization(
            $credential->organization_id,
            'internal.credential.state_changed',
            'credential',
            $credential->id,
            ['credential_id' => $credential->id],
            projectId: $credential->project_id,
            environmentId: $credential->environment_id,
        );
    }

    public function overwriteAfterCommit(Credential $credential): void
    {
        $id = $credential->id;
        $hash = $credential->secret_hash;
        $organizationId = $credential->organization_id;

        // The commit may happen after the tenant context that wrote the change has ended, so set it again.
        DB::afterCommit(function () use ($id, $hash, $organizationId): void {
            $fresh = $this->tenant->run($organizationId, fn (): ?Credential => Credential::query()->find($id));

            if ($fresh !== null) {
                $this->cache->overwrite($hash, CredentialSummaries::of($fresh));
            }
        });
    }
}
