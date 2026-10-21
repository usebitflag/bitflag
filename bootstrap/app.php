<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
        then: function (): void {
            // SDK delivery: no session and no CSRF.
            Route::prefix('sdk/v1')->group(base_path('routes/sdk.php'));

            // Admin console routes live on the admin host only.
            Route::domain(Config::string('bitflag.hosts.admin'))
                ->middleware('web')
                ->group(base_path('routes/admin.php'));

            // End-to-end test routes never load outside local and testing.
            if (config('testing.e2e') && app()->environment(['local', 'testing'])) {
                Route::middleware('web')->group(base_path('routes/testing.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'sdk/*') || $request->expectsJson(),
        );
    })->create();
