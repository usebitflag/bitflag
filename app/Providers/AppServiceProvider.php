<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ResolveActor;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Capabilities\Console\GenerateTypesCommand;
use App\Shared\Capabilities\EnvironmentProtection;
use App\Shared\Capabilities\NoEnvironmentProtection;
use App\Shared\Capabilities\NullTokenScopeMap;
use App\Shared\Capabilities\TokenScopeMap;
use App\Shared\Navigation\PortalNavigation;
use App\Shared\Outbox\Console\OutboxReclaimCommand;
use App\Shared\Outbox\Console\OutboxRecoverCommand;
use App\Shared\Outbox\Console\OutboxWorkCommand;
use App\Shared\Outbox\ConsumerRegistry;
use App\Shared\Persistence\TransactionGuard;
use App\Shared\Tenancy\Schema\TenantColumns;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Time\SystemClock;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

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
        $this->app->singleton(ConsumerRegistry::class);

        $this->app->singleton(ClockInterface::class, SystemClock::class);
        $this->app->bind(TransactionGuard::class, fn (): TransactionGuard => new TransactionGuard(DB::connection()));

        // Defaults until environments define protection and credentials define scopes.
        $this->app->bind(EnvironmentProtection::class, NoEnvironmentProtection::class);
        $this->app->bind(TokenScopeMap::class, NullTokenScopeMap::class);

        // Controllers type-hint Actor to receive the signed-in person. Without a session there is none.
        $this->app->bind(Actor::class, function (Application $app): Actor {
            $request = $app->make(Request::class);
            $actor = $request->attributes->get(ResolveActor::ATTRIBUTE);

            return $actor instanceof Actor ? $actor : throw new AuthenticationException;
        });

        // The organization in context is per request and per job, never shared between them.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TenantColumns::register();

        $this->commands([
            GenerateTypesCommand::class,
            OutboxWorkCommand::class,
            OutboxReclaimCommand::class,
            OutboxRecoverCommand::class,
        ]);
    }
}
