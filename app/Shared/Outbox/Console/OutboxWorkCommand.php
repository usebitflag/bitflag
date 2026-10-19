<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Console;

use App\Shared\Outbox\Dispatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * The outbox worker: keeps fanning stored messages out to their consumers. Runs as its own process; several can run at
 * once because claiming uses SKIP LOCKED.
 */
final class OutboxWorkCommand extends Command
{
    protected $signature = 'outbox:work {--max-time=3600 : Seconds to run before exiting, so a supervisor restarts the process} {--once : Dispatch one batch and exit}';

    protected $description = 'Dispatch outbox messages to their consumers';

    private const IDLE_SLEEP_MICROSECONDS = 250_000;

    private bool $stop = false;

    public function handle(Dispatcher $dispatcher): int
    {
        $workerId = gethostname().':'.getmypid();
        $deadline = time() + (int) $this->option('max-time');

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        do {
            try {
                $dispatched = $dispatcher->dispatchBatch($workerId);
            } catch (Throwable $e) {
                report($e);
                $dispatched = 0;
                sleep(1);
            }

            if ($this->option('once')) {
                break;
            }

            if ($dispatched === 0) {
                usleep(self::IDLE_SLEEP_MICROSECONDS);
            }
        } while (! $this->stop && time() < $deadline);

        return self::SUCCESS;
    }
}
