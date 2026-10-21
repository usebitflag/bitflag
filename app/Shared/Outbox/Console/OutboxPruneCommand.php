<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Console;

use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Outbox\ReceiptStatus;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Psr\Clock\ClockInterface;

/**
 * Deletes delivered outbox messages, and their receipts, after the retention period. Runs daily.
 *
 * A message is eligible when it was dispatched before the cutoff and every receipt succeeded before the cutoff, or it
 * had no interested consumer. A message with any receipt still pending, processing, failed or exhausted is kept.
 */
final class OutboxPruneCommand extends Command
{
    protected $signature = 'outbox:prune
        {--days= : Keep delivered messages this many days (default: outbox.retention_days)}
        {--dry-run : Report how many messages would be deleted and delete nothing}';

    protected $description = 'Delete outbox messages that were delivered longer ago than the retention period';

    public function handle(ClockInterface $clock): int
    {
        $option = $this->option('days');
        $days = is_numeric($option) ? (int) $option : Config::integer('outbox.retention_days');

        if ($days < 1) {
            $this->components->error('The retention period must be at least one day.');

            return self::INVALID;
        }

        $cutoff = $clock->now()->modify("-{$days} days")->format('Y-m-d H:i:s.u');
        $batch = max(1, Config::integer('outbox.prune_batch'));

        if ($this->option('dry-run')) {
            $count = Tenancy::crossTenant('outbox retention', fn (): int => $this->eligible($cutoff)->count());
            $this->components->info("{$count} message(s) are older than {$days} day(s) and fully delivered. Nothing deleted.");

            return self::SUCCESS;
        }

        $deleted = Tenancy::crossTenant('outbox retention', function () use ($cutoff, $batch): int {
            $total = 0;

            do {
                // Receipts go with their message (cascade). Deleting in batches keeps each transaction short.
                $ids = $this->eligible($cutoff)->orderBy('dispatched_at')->limit($batch)->pluck('id')->all();
                $count = $ids === [] ? 0 : OutboxMessageRecord::query()->whereIn('id', $ids)->toBase()->delete();
                $total += $count;
            } while ($count === $batch);

            return $total;
        });

        $this->components->info("Deleted {$deleted} delivered outbox message(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    /**
     * @return Builder<OutboxMessageRecord>
     */
    private function eligible(string $cutoff): Builder
    {
        return OutboxMessageRecord::query()
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', $cutoff)
            ->whereNotExists(static function (QueryBuilder $receipts) use ($cutoff): void {
                $receipts->selectRaw('1')
                    ->from('outbox_receipts')
                    ->whereColumn('outbox_receipts.outbox_message_id', 'outbox_messages.id')
                    ->where(static function (QueryBuilder $unfinished) use ($cutoff): void {
                        $unfinished->where('outbox_receipts.status', '<>', ReceiptStatus::Succeeded->value)
                            ->orWhereNull('outbox_receipts.processed_at')
                            ->orWhere('outbox_receipts.processed_at', '>=', $cutoff);
                    });
            });
    }
}
