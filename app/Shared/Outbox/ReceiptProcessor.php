<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Runs one consumer for one receipt, exactly once at a time.
 *
 * The receipt is claimed with a conditional update, so two jobs for the same receipt cannot both run it: whichever
 * loses the update does nothing. A success marks the receipt succeeded. A failure schedules the next attempt with
 * backoff, and after the last attempt the receipt is exhausted and waits for a person to replay it.
 */
final class ReceiptProcessor
{
    private const LEASE_MARGIN_SECONDS = 30;

    public function __construct(
        private readonly ConsumerRegistry $consumers,
        private readonly ClockInterface $clock,
        private readonly TenantContext $tenant,
    ) {}

    public function process(string $receiptId): void
    {
        $receipt = OutboxReceipt::query()->find($receiptId);

        if ($receipt === null || $receipt->status === ReceiptStatus::Succeeded || $receipt->status === ReceiptStatus::Exhausted) {
            return;
        }

        $consumer = $this->consumers->find($receipt->consumer);

        if ($consumer === null) {
            $this->fail($receipt->id, $receipt->attempts + 1, "No consumer named {$receipt->consumer} is registered.", release: false);

            return;
        }

        $workerId = gethostname().':'.getmypid();

        if (! $this->claim($receipt->id, $workerId, $consumer->timeoutSeconds())) {
            return;
        }

        $attempts = $receipt->attempts + 1;
        $record = OutboxMessageRecord::query()->find($receipt->outbox_message_id);

        if ($record === null) {
            $this->fail($receipt->id, $attempts, 'The message no longer exists.', release: false);

            return;
        }

        try {
            $this->runConsumer($consumer, $record->toMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail($receipt->id, $attempts, $e::class.': '.$e->getMessage(), release: true, queue: $consumer->queue(), organizationId: $receipt->organization_id);

            return;
        }

        OutboxReceipt::query()
            ->whereKey($receipt->id)
            ->where('status', ReceiptStatus::Processing->value)
            ->where('lease_owner', $workerId)
            ->update([
                'status' => ReceiptStatus::Succeeded->value,
                'processed_at' => $this->format($this->clock->now()),
                'lease_owner' => null,
                'lease_until' => null,
                'last_error' => null,
            ]);
    }

    /**
     * Conditional claim. Returns false when another worker holds the receipt, it is done, or its next attempt is not due.
     */
    private function claim(string $receiptId, string $workerId, int $timeoutSeconds): bool
    {
        $now = $this->clock->now();
        $leaseUntil = $now->modify('+'.($timeoutSeconds + self::LEASE_MARGIN_SECONDS).' seconds');

        $claimed = OutboxReceipt::query()
            ->whereKey($receiptId)
            ->whereIn('status', [ReceiptStatus::Pending->value, ReceiptStatus::Failed->value])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $this->format($now)))
            ->update([
                'status' => ReceiptStatus::Processing->value,
                'lease_owner' => $workerId,
                'lease_until' => $this->format($leaseUntil),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        return $claimed === 1;
    }

    private function runConsumer(OutboxConsumer $consumer, OutboxMessage $message): void
    {
        if ($message->scope === OutboxScope::Organization && $message->organizationId !== null) {
            $this->tenant->run($message->organizationId, fn () => $consumer->consume($message));

            return;
        }

        $this->tenant->clear();
        $consumer->consume($message);
    }

    private function fail(string $receiptId, int $attempts, string $error, bool $release, ?string $queue = null, ?string $organizationId = null): void
    {
        $now = $this->clock->now();
        $exhausted = RetrySchedule::isExhausted($attempts);
        $delay = RetrySchedule::delayAfter($attempts);

        OutboxReceipt::query()
            ->whereKey($receiptId)
            ->update([
                'status' => ($exhausted ? ReceiptStatus::Exhausted : ReceiptStatus::Failed)->value,
                'next_attempt_at' => $exhausted ? null : $this->format($now->modify("+{$delay} seconds")),
                'lease_owner' => null,
                'lease_until' => null,
                'last_error' => mb_substr($error, 0, 500),
                'attempts' => $attempts,
            ]);

        if ($exhausted || ! $release || $queue === null) {
            return;
        }

        try {
            DeliverOutboxMessage::dispatch($receiptId, $organizationId)->onQueue($queue)->delay($delay);
            OutboxReceipt::query()->whereKey($receiptId)->update(['last_signalled_at' => $this->format($now)]);
        } catch (Throwable) {
            // The receipt already says when it is due; outbox:recover signals it if this push was lost.
        }
    }

    private function format(DateTimeInterface $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }
}
