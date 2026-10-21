<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Console;

use App\Shared\Outbox\OutboxHealth;
use Illuminate\Console\Command;
use Psr\Clock\ClockInterface;

/**
 * Shows what the outbox is holding. With --max-lag it also works as a check: it fails when overdue work has been
 * waiting longer than that many seconds, which is how a stopped worker shows up.
 */
final class OutboxStatusCommand extends Command
{
    protected $signature = 'outbox:status
        {--json : Print the result as JSON}
        {--max-lag= : Fail when the oldest overdue work has waited longer than this many seconds}
        {--fail-on-exhausted : Fail when any receipt has run out of attempts and waits for a person}';

    protected $description = 'Show the outbox backlog, failures and the age of the oldest overdue work';

    public function handle(OutboxHealth $health, ClockInterface $clock): int
    {
        $snapshot = $health->snapshot($clock->now());

        $maxLag = is_numeric($this->option('max-lag')) ? (int) $this->option('max-lag') : null;
        $lag = $snapshot['oldest_overdue_seconds'];
        $problems = [];

        if ($maxLag !== null && $lag !== null && $lag > $maxLag) {
            $problems[] = "The oldest overdue work has waited {$lag} second(s), more than {$maxLag}.";
        }

        if ($this->option('fail-on-exhausted') && $snapshot['receipts']['exhausted'] > 0) {
            $problems[] = "{$snapshot['receipts']['exhausted']} receipt(s) ran out of attempts and need a replay.";
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([...$snapshot, 'ok' => $problems === [], 'problems' => $problems], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>Messages</>', '');
        $this->components->twoColumnDetail('Waiting to be dispatched', (string) $snapshot['messages']['waiting']);
        $this->components->twoColumnDetail('Dispatched', (string) $snapshot['messages']['dispatched']);
        $this->components->twoColumnDetail('<fg=gray>Receipts</>', '');

        foreach ($snapshot['receipts'] as $status => $count) {
            $this->components->twoColumnDetail(ucfirst($status), (string) $count);
        }

        $this->components->twoColumnDetail('<fg=gray>Lag</>', '');
        $this->components->twoColumnDetail('Oldest overdue work', $lag === null ? 'none' : "{$lag} second(s)");

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
