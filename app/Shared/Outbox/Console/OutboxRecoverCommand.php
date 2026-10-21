<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Console;

use App\Shared\Outbox\ReceiptRecovery;
use Illuminate\Console\Command;

/**
 * Rebuilds outstanding work from the receipts after a crash or a lost queue. Runs every minute.
 */
final class OutboxRecoverCommand extends Command
{
    protected $signature = 'outbox:recover';

    protected $description = 'Release expired receipt leases and signal receipts that are due';

    public function handle(ReceiptRecovery $recovery): int
    {
        $result = $recovery->recover();

        $this->components->info("Released {$result['released']} expired lease(s); signalled {$result['signalled']} receipt(s).");

        return self::SUCCESS;
    }
}
