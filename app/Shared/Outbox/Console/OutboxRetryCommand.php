<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Console;

use App\Shared\Outbox\OutboxReceipt;
use App\Shared\Outbox\ReceiptRecovery;
use App\Shared\Outbox\ReceiptStatus;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Psr\Clock\ClockInterface;

/**
 * Puts failed or exhausted receipts back to pending, so their consumer tries again from the first attempt. Use it after
 * fixing the cause of a failure (see `outbox:status`). It never touches receipts that succeeded or are still running.
 */
final class OutboxRetryCommand extends Command
{
    protected $signature = 'outbox:retry
        {--id= : Retry one receipt by id}
        {--consumer= : Retry every failed or exhausted receipt of one consumer}
        {--all : Retry every failed or exhausted receipt}
        {--force : Allow the command in production}';

    protected $description = 'Retry failed or exhausted outbox receipts after the cause has been fixed';

    public function handle(ReceiptRecovery $recovery, ClockInterface $clock): int
    {
        $id = $this->option('id');
        $consumer = $this->option('consumer');

        if (! is_string($id) && ! is_string($consumer) && ! $this->option('all')) {
            $this->components->error('Say what to retry: --id=<receipt>, --consumer=<name> or --all.');

            return self::INVALID;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('This is production. Fix the cause first, then run it again with --force.');

            return self::FAILURE;
        }

        $now = $clock->now()->format('Y-m-d H:i:s.u');

        $retried = Tenancy::crossTenant('outbox retry', fn (): int => OutboxReceipt::query()
            ->whereIn('status', [ReceiptStatus::Failed->value, ReceiptStatus::Exhausted->value])
            ->when(is_string($id), fn ($query) => $query->whereKey($id))
            ->when(is_string($consumer), fn ($query) => $query->where('consumer', $consumer))
            ->update([
                'status' => ReceiptStatus::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'last_signalled_at' => null,
                'lease_owner' => null,
                'lease_until' => null,
                'last_error' => null,
            ]));

        $recovery->recover();

        $this->components->info("Put {$retried} receipt(s) back to pending.");

        return self::SUCCESS;
    }
}
