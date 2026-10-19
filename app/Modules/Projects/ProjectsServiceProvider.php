<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the Projects module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class ProjectsServiceProvider extends ServiceProvider
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
