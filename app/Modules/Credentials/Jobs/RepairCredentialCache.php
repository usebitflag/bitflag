<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Jobs;

use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialCache;
use App\Modules\Credentials\Support\CredentialSummaries;
use App\Shared\Outbox\OutboxConsumer;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;

/**
 * Writes a credential's current state into the cache after `internal.credential.state_changed`. The action that changed the
 * state does the same inline after it commits; this repeats it from MySQL, so a crash between the commit and the inline write
 * is repaired and a stale entry lives at most as long as its time to live. Writing the current state twice is harmless.
 */
final class RepairCredentialCache implements OutboxConsumer
{
    public function __construct(
        private readonly CredentialCache $cache,
        private readonly TenantContext $tenant,
    ) {}

    public function name(): string
    {
        return 'credentials.repair_cache';
    }

    public function handles(): array
    {
        return ['internal.credential.state_changed'];
    }

    public function queue(): string
    {
        return 'publication';
    }

    public function timeoutSeconds(): int
    {
        return 30;
    }

    public function consume(OutboxMessage $message): void
    {
        $credentialId = $message->payload['credential_id'] ?? null;

        if (! is_string($credentialId) || $message->organizationId === null) {
            return;
        }

        $this->tenant->run($message->organizationId, function () use ($credentialId): void {
            $credential = Credential::query()->find($credentialId);

            if ($credential !== null) {
                $this->cache->overwrite($credential->secret_hash, CredentialSummaries::of($credential));
            }
        });
    }
}
