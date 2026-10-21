<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Http\Middleware\AssignRequestId;
use App\Shared\Actors\Actor;
use App\Shared\Persistence\TransactionGuard;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextMissing;
use Illuminate\Http\Request;
use Psr\Clock\ClockInterface;

/**
 * Writes audit entries. Call it inside the transaction of the change it records, so the entry exists exactly when the
 * change does:
 *
 *   DB::transaction(function () use (...) {
 *       // write the change
 *       $audit->record($actor, 'flag.created', new AuditTarget('flag', $id, $name));
 *   });
 */
final class AuditRecorder
{
    public function __construct(
        private readonly AuditCatalog $catalog,
        private readonly Redactor $redactor,
        private readonly TransactionGuard $transactions,
        private readonly ClockInterface $clock,
        private readonly TenantContext $tenant,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<array-key, mixed>  $details  Redacted before storage; never pass secrets or subject data.
     *
     * @throws AuditOutsideTransaction when called outside a transaction
     * @throws UnknownAuditAction when the action name was not registered
     */
    public function record(
        Actor $actor,
        string $action,
        AuditTarget $target,
        array $details = [],
        ?string $reason = null,
        ?RevisionChange $revisions = null,
        ?string $projectId = null,
        ?string $environmentId = null,
    ): AuditEvent {
        try {
            $this->transactions->assertInTransaction('An audit entry');
        } catch (\LogicException $e) {
            throw new AuditOutsideTransaction($e->getMessage(), previous: $e);
        }

        if (! $this->catalog->has($action)) {
            throw UnknownAuditAction::named($action);
        }

        $organizationId = $this->tenant->organizationId() ?? throw TenantContextMissing::forWrite(AuditEvent::class);
        $requestId = $this->request->attributes->get(AssignRequestId::ATTRIBUTE);

        $event = new AuditEvent([
            ...$actor->auditAttributes(),
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'environment_id' => $environmentId,
            'action' => $action,
            'target_type' => $target->type,
            'target_id' => $target->id,
            'target_display' => $target->display,
            'from_revision' => $revisions?->from,
            'to_revision' => $revisions?->to,
            'reason' => $reason,
            'request_id' => is_string($requestId) ? $requestId : null,
            'details' => $details === [] ? null : $this->redactor->redact($details),
            'occurred_at' => $this->clock->now(),
        ]);
        $event->save();

        return $event;
    }
}
