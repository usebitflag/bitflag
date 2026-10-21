<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Shared\Outbox\OutboxConsumer;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use RuntimeException;

/**
 * A consumer for tests. It records every call and every distinct effect, can be told to fail, and notes the tenant
 * context it ran in. Effects are keyed by message ID, like a real idempotent consumer.
 */
final class RecordingConsumer implements OutboxConsumer
{
    /** @var list<string> message IDs, one entry per call */
    public static array $calls = [];

    /** @var array<string, true> message IDs whose effect has been applied */
    public static array $effects = [];

    /** @var list<string|null> the organization in context during each call */
    public static array $contexts = [];

    public static int $failures = 0;

    public static function reset(): void
    {
        self::$calls = [];
        self::$effects = [];
        self::$contexts = [];
        self::$failures = 0;
    }

    public function name(): string
    {
        return 'tests.recording';
    }

    public function handles(): array
    {
        return ['internal.test_happened', 'internal.account_test'];
    }

    public function queue(): string
    {
        return 'default';
    }

    public function timeoutSeconds(): int
    {
        return 60;
    }

    public function consume(OutboxMessage $message): void
    {
        self::$calls[] = $message->id;
        self::$contexts[] = app(TenantContext::class)->organizationId();

        if (self::$failures > 0) {
            self::$failures--;

            throw new RuntimeException('the consumer failed');
        }

        self::$effects[$message->id] = true;
    }
}
