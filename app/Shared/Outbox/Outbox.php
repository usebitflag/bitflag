<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Http\Middleware\AssignRequestId;
use App\Shared\Audit\Redactor;
use App\Shared\Persistence\TransactionGuard;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Records that something happened, in the same transaction as the change, so the notice exists exactly when the change
 * does. A worker later hands the message to every interested consumer.
 *
 *   DB::transaction(function () use (...) {
 *       // write the change
 *       $outbox->push(OutboxMessage::forOrganization($orgId, 'flag.changed', 'flag', $flagId, ['version' => 4]));
 *   });
 */
final class Outbox
{
    public function __construct(
        private readonly TransactionGuard $transactions,
        private readonly Redactor $redactor,
        private readonly ClockInterface $clock,
        private readonly Request $request,
    ) {}

    public function push(OutboxMessage $message): void
    {
        $this->transactions->assertInTransaction('An outbox message');

        if ($this->redactor->redact($message->payload) !== $message->payload) {
            throw new InvalidArgumentException('An outbox payload carries identifiers and versions only, never secrets, context, long text or subject data.');
        }

        $now = $this->clock->now();
        $requestId = $this->request->attributes->get(AssignRequestId::ATTRIBUTE);

        OutboxMessageRecord::query()->create([
            'id' => $message->id,
            'scope' => $message->scope->value,
            'organization_id' => $message->organizationId,
            'user_id' => $message->userId,
            'project_id' => $message->projectId,
            'environment_id' => $message->environmentId,
            'type' => $message->type,
            'aggregate_type' => $message->aggregateType,
            'aggregate_id' => $message->aggregateId,
            'payload' => $message->payload,
            'request_id' => is_string($requestId) ? $requestId : $message->requestId,
            'occurred_at' => $now,
            'available_at' => $now,
        ]);
    }
}
