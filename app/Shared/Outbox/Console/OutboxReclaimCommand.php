<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Console;

use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Psr\Clock\ClockInterface;

/**
 * Releases messages whose worker stopped before dispatching them, so another worker can claim them.
 */
final class OutboxReclaimCommand extends Command
{
    protected $signature = 'outbox:reclaim';

    protected $description = 'Release expired outbox message claims';

    public function handle(ClockInterface $clock): int
    {
        $released = Tenancy::crossTenant('outbox claim release', fn (): int => OutboxMessageRecord::query()
            ->whereNull('dispatched_at')
            ->whereNotNull('claimed_until')
            ->where('claimed_until', '<', $clock->now()->format('Y-m-d H:i:s.u'))
            ->update(['claimed_by' => null, 'claimed_until' => null]));

        $this->components->info("Released {$released} expired claim(s).");

        return self::SUCCESS;
    }
}
