<?php

declare(strict_types=1);

namespace App\Shared\Idempotency\Console;

use App\Shared\Idempotency\IdempotencyRecord;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Psr\Clock\ClockInterface;

/**
 * Removes idempotency records past their expiry. Runs hourly.
 */
final class IdempotencyPruneCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete expired idempotency records';

    public function handle(ClockInterface $clock): int
    {
        $deleted = Tenancy::crossTenant('idempotency record expiry', fn (): int => IdempotencyRecord::query()
            ->where('expires_at', '<', $clock->now()->format('Y-m-d H:i:s.u'))
            ->toBase()
            ->delete());

        $this->components->info("Deleted {$deleted} expired idempotency record(s).");

        return self::SUCCESS;
    }
}
