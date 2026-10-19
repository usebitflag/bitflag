<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the Audit module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class AuditServiceProvider extends ServiceProvider
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
