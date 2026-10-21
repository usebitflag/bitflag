<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the Integrations module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class IntegrationsServiceProvider extends ServiceProvider
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
