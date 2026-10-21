<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

/**
 * Reacts to outbox messages: sends an email, updates a cache, delivers a webhook. Delivery is at least once, so a
 * consumer must be idempotent: running twice for the same message must not repeat its effect. The receipt row is its
 * deduplication key.
 */
interface OutboxConsumer
{
    /**
     * A stable name, stored on the receipt. Do not change it once deployed.
     */
    public function name(): string;

    /**
     * The message types this consumer handles.
     *
     * @return list<string>
     */
    public function handles(): array;

    /**
     * The queue its jobs run on, so a slow consumer cannot starve a critical one.
     */
    public function queue(): string;

    /**
     * The longest one run may take, in seconds.
     */
    public function timeoutSeconds(): int;

    public function consume(OutboxMessage $message): void;
}
