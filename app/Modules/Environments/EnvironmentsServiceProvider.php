<?php

declare(strict_types=1);

namespace App\Modules\Environments;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the Environments module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class EnvironmentsServiceProvider extends ServiceProvider
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
