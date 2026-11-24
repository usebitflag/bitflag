<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use App\Shared\Audit\AuditCatalog;
use App\Shared\Navigation\NavigationEntry;
use App\Shared\Navigation\NavigationGroup;
use App\Shared\Navigation\PortalNavigation;
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
        $this->app->make(AuditCatalog::class)->register('project.created', 'project.updated');

        // The Overview is the first page of every project.
        $this->app->make(PortalNavigation::class)->register(
            new NavigationEntry('overview', 'Overview', 'house', NavigationGroup::Project, 10, 'project.overview'),
        );
    }
}
