<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Data\PlaintextSecret;
use App\Modules\Credentials\Data\RotateCredentialData;
use App\Modules\Credentials\Data\RotatedCredential;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialState;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Replaces an active key with a new one of the same kind, environment, name, permissions and expiry choice. The old key keeps
 * working for the chosen overlap (1 hour, 24 hours or 7 days) and is revoked when it ends. The replacement's secret is shown
 * once. Rotation is never gated by the plan.
 */
final class RotateCredential
{
    /** The overlaps the form offers, in seconds. */
    public const OVERLAPS = [3600, 86400, 604800];

    public function __construct(
        private readonly TokenGenerator $generator,
        private readonly SecretHasher $hasher,
        private readonly CredentialState $state,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ResourceNotFound
     * @throws ValidationException
     */
    public function handle(Actor $actor, RotateCredentialData $data): RotatedCredential
    {
        if ($actor->id() === null || ! $actor->can(Capability::CredentialsManage, new EnvironmentScope($data->organizationId, $data->projectId, $data->environmentId))) {
            throw new AuthorizationDenied;
        }

        if (! in_array($data->overlapSeconds, self::OVERLAPS, true)) {
            throw ValidationException::withMessages(['overlap' => 'Choose 1 hour, 24 hours or 7 days.']);
        }

        return $this->tenant->run($data->organizationId, fn (): RotatedCredential => DB::transaction(function () use ($actor, $data): RotatedCredential {
            $old = Credential::query()->lockForUpdate()->where('environment_id', $data->environmentId)->find($data->credentialId);

            if ($old === null) {
                throw new ResourceNotFound;
            }

            if ($old->status !== CredentialStatus::Active) {
                throw ValidationException::withMessages(['credential' => 'Only an active key can be rotated.']);
            }

            $now = $this->clock->now();
            $stopsAt = $now->modify("+{$data->overlapSeconds} seconds");
            $generated = $this->generator->generate($old->type, $this->tagOf($old->display_prefix));

            $replacement = Credential::query()->create([
                'organization_id' => $old->organization_id,
                'project_id' => $old->project_id,
                'environment_id' => $old->environment_id,
                'type' => $old->type,
                'label' => $old->label,
                'display_prefix' => $generated->displayPrefix,
                'last4' => $generated->last4,
                'secret_hash' => $this->hasher->hash($generated->secret),
                'pepper_id' => $this->hasher->currentPepperId(),
                'scopes' => $old->scopes,
                'ttl_days' => $old->ttl_days,
                'expires_at' => $old->ttl_days === null ? null : $now->modify("+{$old->ttl_days} days"),
                'status' => CredentialStatus::Active,
                'rotated_from_id' => $old->id,
                'audience' => $old->audience,
                'created_by' => $actor->id(),
            ]);

            $old->forceFill(['status' => CredentialStatus::Rotating, 'overlap_ends_at' => $stopsAt, 'replaced_by_id' => $replacement->id])->save();

            $this->audit->record(
                $actor,
                'key.rotated',
                new AuditTarget('key', $old->id, $old->label),
                ['label' => $old->label, 'old_last4' => $old->last4, 'new_last4' => $replacement->last4, 'replacement_id' => $replacement->id, 'overlap_seconds' => $data->overlapSeconds],
                projectId: $old->project_id,
                environmentId: $old->environment_id,
            );
            $this->outbox->push(OutboxMessage::forOrganization($old->organization_id, 'internal.key.rotated', 'key', $replacement->id, ['credential_id' => $replacement->id, 'old_credential_id' => $old->id], projectId: $old->project_id, environmentId: $old->environment_id));
            $this->outbox->push($this->state->message($old));
            $this->state->overwriteAfterCommit($old);

            return new RotatedCredential($old->id, $replacement->id, new PlaintextSecret($generated->secret), $stopsAt);
        }));
    }

    /** The environment tag a key was made with, so the replacement looks like the original. */
    private function tagOf(string $displayPrefix): ?string
    {
        return preg_match('/^bf_(?:srv|pub|mgt)_([a-z0-9]{1,4})_$/', $displayPrefix, $parts) === 1 ? $parts[1] : null;
    }
}
