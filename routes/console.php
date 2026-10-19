<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Outbox: release claims whose worker died, and rebuild outstanding work from the receipts.
Schedule::command('outbox:reclaim')->everyMinute()->withoutOverlapping();
Schedule::command('outbox:recover')->everyMinute()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
