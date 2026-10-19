<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the Analytics module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
