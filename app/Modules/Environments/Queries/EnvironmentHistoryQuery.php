<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Shared\Audit\AuditEvent;

/**
 * The newest history entries of one environment, read from the audit log. Runs inside the organization's tenant context.
 */
final class EnvironmentHistoryQuery
{
    /**
     * @return list<array{id: string, action: string, actor: string, target: string|null, reason: string|null, occurredAt: int}>
     */
    public function for(string $environmentId, int $limit = 50): array
    {
        return array_values(AuditEvent::query()
            ->where('environment_id', $environmentId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (AuditEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'actor' => $event->actor_display,
                'target' => $event->target_display,
                'reason' => $event->reason,
                'occurredAt' => $event->occurred_at->getTimestamp(),
            ])
            ->all());
    }
}
