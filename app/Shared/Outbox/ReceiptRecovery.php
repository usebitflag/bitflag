<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Tenancy\Tenancy;
use DateTimeInterface;
use Psr\Clock\ClockInterface;

/**
 * Rebuilds outstanding work from the receipts in MySQL after a crash, a lost job or a flushed queue.
 *
 * 1. A receipt stuck in `processing` past its lease has lost its worker: it goes back to `failed` with the attempt
 *    counted, or to `exhausted` if that was the last one.
 * 2. A receipt that is due and was not signalled in the last two minutes gets a fresh queue job.
 *
 * Because claiming is conditional, a duplicate signal never runs a consumer twice at once.
 */
final class ReceiptRecovery
{
    private const RESIGNAL_AFTER_SECONDS = 120;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array{released: int, signalled: int}
     */
    public function recover(): array
    {
        return Tenancy::crossTenant('outbox recovery', function (): array {
            return ['released' => $this->releaseExpiredLeases(), 'signalled' => $this->signalDueReceipts()];
        });
    }

    private function releaseExpiredLeases(): int
    {
        $now = $this->clock->now();
        $expired = OutboxReceipt::query()
            ->where('status', ReceiptStatus::Processing->value)
            ->where('lease_until', '<', $this->format($now))
            ->get();

        foreach ($expired as $receipt) {
            $exhausted = RetrySchedule::isExhausted($receipt->attempts);
            $delay = RetrySchedule::delayAfter($receipt->attempts);

            OutboxReceipt::query()
                ->whereKey($receipt->id)
                ->where('status', ReceiptStatus::Processing->value)
                ->update([
                    'status' => ($exhausted ? ReceiptStatus::Exhausted : ReceiptStatus::Failed)->value,
                    'next_attempt_at' => $exhausted ? null : $this->format($now->modify("+{$delay} seconds")),
                    'lease_owner' => null,
                    'lease_until' => null,
                    'last_error' => 'The worker stopped before finishing; the lease expired.',
                ]);
        }

        return $expired->count();
    }

    private function signalDueReceipts(): int
    {
        $now = $this->clock->now();
        $stale = $this->format($now->modify('-'.self::RESIGNAL_AFTER_SECONDS.' seconds'));

        $due = OutboxReceipt::query()
            ->whereIn('status', [ReceiptStatus::Pending->value, ReceiptStatus::Failed->value])
            ->where('next_attempt_at', '<=', $this->format($now))
            ->where(fn ($query) => $query->whereNull('last_signalled_at')->orWhere('last_signalled_at', '<', $stale))
            ->get();

        $this->dispatcher->signal(array_values($due->all()));

        return $due->count();
    }

    private function format(DateTimeInterface $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }
}
