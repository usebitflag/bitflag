<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Audit\AuditCatalog;
use App\Shared\Navigation\PortalNavigation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registries that module service providers add to when they boot.
        $this->app->singleton(PortalNavigation::class);
        $this->app->singleton(AuditCatalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
