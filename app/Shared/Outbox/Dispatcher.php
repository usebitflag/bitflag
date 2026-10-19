<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Tenancy\Tenancy;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;

/**
 * Fans stored outbox messages out to their consumers.
 *
 * 1. Claim: lock a batch of undispatched messages with FOR UPDATE SKIP LOCKED, so concurrent workers never take the
 *    same row, and mark them with a short lease.
 * 2. Fan out: in one transaction create one pending receipt per interested consumer and mark the message dispatched.
 *    Creating receipts is idempotent, so repeating this step is harmless.
 * 3. Signal: after that commit, push a queue job per receipt. The job is only a signal; the receipt is the work.
 *
 * A crash between any two steps loses nothing: an expired claim is released by outbox:reclaim, and a receipt that was
 * never signalled is signalled by outbox:recover.
 */
final class Dispatcher
{
    private const CLAIM_SECONDS = 60;

    public function __construct(
        private readonly ConsumerRegistry $consumers,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Runs all three steps for one batch and returns how many messages were dispatched.
     */
    public function dispatchBatch(string $workerId, int $limit = 100, ?Connection $connection = null): int
    {
        return Tenancy::crossTenant('outbox dispatcher', function () use ($workerId, $limit, $connection): int {
            $connection ??= DB::connection();
            $receipts = [];
            $claimed = $this->claim($workerId, $limit, $connection);

            foreach ($claimed as $message) {
                array_push($receipts, ...$this->fanOut($message, $workerId, $connection));
            }

            $this->signal($receipts);

            return count($claimed);
        });
    }

    /**
     * Step 1. Locks and leases a batch of undispatched messages.
     *
     * @return list<OutboxMessageRecord>
     */
    public function claim(string $workerId, int $limit, Connection $connection): array
    {
        $now = $this->clock->now();
        $until = $now->modify('+'.self::CLAIM_SECONDS.' seconds');
        $name = $connection->getName();

        return $connection->transaction(function () use ($workerId, $limit, $now, $until, $name): array {
            $messages = OutboxMessageRecord::on($name)
                ->whereNull('dispatched_at')
                ->where('available_at', '<=', $this->format($now))
                ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<', $this->format($now)))
                ->orderBy('available_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($messages->isEmpty()) {
                return [];
            }

            OutboxMessageRecord::on($name)
                ->whereIn('id', $messages->pluck('id')->all())
                ->update(['claimed_by' => $workerId, 'claimed_until' => $this->format($until)]);

            return array_values($messages->all());
        });
    }

    /**
     * Step 2. Creates the receipts for a claimed message and marks it dispatched.
     *
     * @return list<OutboxReceipt> the receipts that now exist for the message, ready to signal
     */
    public function fanOut(OutboxMessageRecord $message, string $workerId, Connection $connection): array
    {
        $now = $this->format($this->clock->now());
        $name = $connection->getName();
        $consumers = $this->consumers->consumersFor($message->type);

        return $connection->transaction(function () use ($message, $workerId, $name, $now, $consumers): array {
            foreach ($consumers as $consumer) {
                OutboxReceipt::on($name)->insertOrIgnore([[
                    'id' => strtolower((string) Str::ulid()),
                    'outbox_message_id' => $message->id,
                    'scope' => $message->scope,
                    'organization_id' => $message->organization_id,
                    'user_id' => $message->user_id,
                    'consumer' => $consumer->name(),
                    'status' => ReceiptStatus::Pending->value,
                    'attempts' => 0,
                    'next_attempt_at' => $now,
                ]]);
            }

            $dispatched = OutboxMessageRecord::on($name)
                ->whereKey($message->id)
                ->whereNull('dispatched_at')
                ->where('claimed_by', $workerId)
                ->update(['dispatched_at' => $now]);

            if ($dispatched === 0 || $consumers === []) {
                return [];
            }

            return array_values(OutboxReceipt::on($name)
                ->where('outbox_message_id', $message->id)
                ->whereIn('consumer', array_map(static fn (OutboxConsumer $c): string => $c->name(), $consumers))
                ->get()
                ->all());
        });
    }

    /**
     * Step 3. Pushes a queue job for each receipt and records when it did.
     *
     * @param  list<OutboxReceipt>  $receipts
     */
    public function signal(array $receipts): void
    {
        $now = $this->format($this->clock->now());

        foreach ($receipts as $receipt) {
            $consumer = $this->consumers->find($receipt->consumer);

            if ($consumer === null) {
                continue;
            }

            DeliverOutboxMessage::dispatch($receipt->id, $receipt->organization_id)->onQueue($consumer->queue());

            OutboxReceipt::query()->whereKey($receipt->id)->update(['last_signalled_at' => $now]);
        }
    }

    private function format(DateTimeInterface $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }
}
