<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Models\Credential;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Leaves one `internal.credential.expiring` message for each credential that expires within the next 7 days, once, so the
 * notifications module can tell the person who made it and the project's Owners. System-only: the scheduler calls it daily.
 */
final class NotifyExpiringCredentials
{
    public const DAYS_AHEAD = 7;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly Repository $cache,
        private readonly ClockInterface $clock,
    ) {}

    /** @return int how many credentials were announced */
    public function handle(string $organizationId, string $credentialId): int
    {
        $marker = "credential-expiring-notified:{$credentialId}";

        if ($this->cache->has($marker)) {
            return 0;
        }

        $announced = $this->tenant->run($organizationId, fn (): int => DB::transaction(function () use ($credentialId): int {
            $credential = Credential::query()->find($credentialId);
            $limit = $this->clock->now()->modify('+'.self::DAYS_AHEAD.' days');

            if ($credential === null || $credential->status !== CredentialStatus::Active || $credential->expires_at === null
                || $credential->expires_at > $limit || $credential->expires_at <= $this->clock->now()) {
                return 0;
            }

            $this->outbox->push(OutboxMessage::forOrganization($credential->organization_id, 'internal.credential.expiring', 'credential', $credential->id, ['credential_id' => $credential->id, 'expires_at' => $credential->expires_at->getTimestamp()], projectId: $credential->project_id, environmentId: $credential->environment_id));

            return 1;
        }));

        if ($announced === 1) {
            $this->cache->put($marker, true, 60 * 60 * 24 * 10);
        }

        return $announced;
    }
}
