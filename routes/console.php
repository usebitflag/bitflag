<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('idempotency:prune')->hourly();

// Outbox: release claims whose worker died, and rebuild outstanding work from the receipts.
Schedule::command('outbox:reclaim')->everyMinute()->withoutOverlapping();
Schedule::command('outbox:recover')->everyMinute()->withoutOverlapping();

// Delivered messages are deleted after the retention period; anything still outstanding is kept.
Schedule::command('invitations:expire')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('identity:prune')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('outbox:prune')->dailyAt('03:15')->withoutOverlapping()->onOneServer();

// API key usage: copy the last-use times recorded in Redis into the database.
Schedule::command('credentials:flush-last-used')->everyMinute()->withoutOverlapping();

Schedule::command('credentials:complete-rotations')->everyMinute()->withoutOverlapping();
Schedule::command('credentials:expire')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
