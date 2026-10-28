<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialState;
use App\Shared\Actors\Actor;
use App\Shared\Actors\ActorType;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Revokes a credential at once. A credential is revoked by someone who manages the project's credentials, by the person a
 * personal token belongs to, or by the system (a finished rotation, an archived environment, a removed member). Revoking is
 * never gated by the plan and is allowed in every project that has not been purged. Revoking one that is already revoked does
 * nothing.
 *
 * The cache entry is overwritten after the commit, and a repair message is queued in the same transaction, so the next request
 * with the secret is rejected and a crash before the cache write is repaired from MySQL.
 */
final class RevokeCredential
{
    public function __construct(
        private readonly CredentialState $state,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ResourceNotFound
     */
    public function handle(Actor $actor, RevokeCredentialData $data): void
    {
        $this->tenant->run($data->organizationId, fn () => DB::transaction(function () use ($actor, $data): void {
            $credential = Credential::query()->lockForUpdate()->find($data->credentialId);

            if ($credential === null) {
                throw new ResourceNotFound;
            }

            if (! $this->mayRevoke($actor, $credential)) {
                throw new AuthorizationDenied;
            }

            if ($credential->status === CredentialStatus::Revoked) {
                return;
            }

            $credential->forceFill([
                'status' => CredentialStatus::Revoked,
                'revoked_at' => $this->clock->now(),
                'revoked_by' => $actor->type() === ActorType::User ? $actor->id() : null,
                'revoke_reason' => $data->reason,
            ])->save();

            $personal = $credential->type === CredentialType::Personal;

            $this->audit->record(
                $actor,
                $personal ? 'token.revoked' : 'key.revoked',
                new AuditTarget($personal ? 'token' : 'key', $credential->id, $credential->label),
                ['type' => $credential->type->value, 'label' => $credential->label, 'prefix' => $credential->display_prefix, 'last4' => $credential->last4, 'reason' => $data->reason->value],
                projectId: $credential->project_id,
                environmentId: $credential->environment_id,
            );

            if (! $personal) {
                $this->outbox->push(OutboxMessage::forOrganization($credential->organization_id, 'key.revoked', 'key', $credential->id, ['credential_id' => $credential->id, 'reason' => $data->reason->value], projectId: $credential->project_id, environmentId: $credential->environment_id));
            }

            $this->outbox->push($this->state->message($credential));
            $this->state->overwriteAfterCommit($credential);
        }));
    }

    private function mayRevoke(Actor $actor, Credential $credential): bool
    {
        if ($actor->type() === ActorType::System) {
            return true;
        }

        if ($credential->type === CredentialType::Personal) {
            return ($actor->id() !== null && $actor->id() === $credential->user_id && $actor->viaTokenId() === null)
                || $actor->can(Capability::CredentialsManage, new ProjectScope($credential->organization_id, $credential->project_id));
        }

        return $credential->environment_id !== null
            && $actor->can(Capability::CredentialsManage, new EnvironmentScope($credential->organization_id, $credential->project_id, $credential->environment_id));
    }
}
