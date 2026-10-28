<?php

declare(strict_types=1);

namespace App\Modules\Environments;

use App\Modules\Environments\Queries\PolicyEnvironmentProtection;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Capabilities\EnvironmentProtection;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Environments module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class EnvironmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Protection comes from the current version of each environment's policy. One instance per request or job, so a
        // remembered answer never outlives it.
        $this->app->scoped(EnvironmentProtection::class, PolicyEnvironmentProtection::class);
    }

    public function boot(): void
    {
        $this->app->make(AuditCatalog::class)->register('environment.created', 'environment.reordered');
    }
}
