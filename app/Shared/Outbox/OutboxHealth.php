<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * A read-only picture of the outbox: how much is waiting, how much has failed for good, and how long the oldest
 * overdue work has been waiting. That last number is what tells you a worker is down, because a stopped worker fails
 * nothing; work just sits.
 */
final class OutboxHealth
{
    /**
     * @return array{
     *     messages: array{waiting: int, dispatched: int},
     *     receipts: array<string, int>,
     *     oldest_overdue_seconds: int|null
     * }
     */
    public function snapshot(DateTimeImmutable $now): array
    {
        $at = $now->format('Y-m-d H:i:s.u');

        return Tenancy::crossTenant('outbox status', function () use ($now, $at): array {
            $receipts = [];
            foreach (ReceiptStatus::cases() as $status) {
                $receipts[$status->value] = OutboxReceipt::query()->where('status', $status->value)->count();
            }

            // Work that was due and has not been done: messages nobody has dispatched, receipts due for an attempt,
            // and receipts whose worker lost its lease.
            $due = array_filter([
                OutboxMessageRecord::query()->whereNull('dispatched_at')->where('available_at', '<=', $at)->oldest('available_at')->first()?->available_at,
                OutboxReceipt::query()->whereIn('status', [ReceiptStatus::Pending->value, ReceiptStatus::Failed->value])->where('next_attempt_at', '<=', $at)->oldest('next_attempt_at')->first()?->next_attempt_at,
                OutboxReceipt::query()->where('status', ReceiptStatus::Processing->value)->where('lease_until', '<', $at)->oldest('lease_until')->first()?->lease_until,
            ], static fn (?CarbonImmutable $moment): bool => $moment !== null);

            $oldest = $due === [] ? null : min(array_map(static fn (CarbonImmutable $moment): int => $moment->getTimestamp(), $due));

            return [
                'messages' => [
                    'waiting' => OutboxMessageRecord::query()->whereNull('dispatched_at')->count(),
                    'dispatched' => OutboxMessageRecord::query()->whereNotNull('dispatched_at')->count(),
                ],
                'receipts' => $receipts,
                'oldest_overdue_seconds' => $oldest === null ? null : max(0, $now->getTimestamp() - $oldest),
            ];
        });
    }
}
