<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialState;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Outbox\Outbox;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Marks a credential whose expiry has passed as expired. Authentication already rejects it at the expiry time, so this only
 * tidies the list and records the event. System-only: the scheduler calls it for each credential that is due.
 */
final class ExpireCredential
{
    public function __construct(
        private readonly CredentialState $state,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(string $organizationId, string $credentialId): void
    {
        $this->tenant->run($organizationId, fn () => DB::transaction(function () use ($credentialId): void {
            $credential = Credential::query()->lockForUpdate()->find($credentialId);

            if ($credential === null || ! in_array($credential->status, [CredentialStatus::Active, CredentialStatus::Rotating], true)
                || $credential->expires_at === null || $credential->expires_at > $this->clock->now()) {
                return;
            }

            $credential->forceFill(['status' => CredentialStatus::Expired])->save();

            $this->audit->record(
                Actor::system('credential expiry'),
                'key.expired',
                new AuditTarget('key', $credential->id, $credential->label),
                ['type' => $credential->type->value, 'label' => $credential->label, 'prefix' => $credential->display_prefix, 'last4' => $credential->last4],
                projectId: $credential->project_id,
                environmentId: $credential->environment_id,
            );
            $this->outbox->push($this->state->message($credential));
            $this->state->overwriteAfterCommit($credential);
        }));
    }
}
