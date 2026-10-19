<?php

declare(strict_types=1);

namespace App\Admin;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff admin console. It runs on its own host with its own guard and session cookie, so a portal session never
 * opens it and a staff session never opens the portal. Resources and support actions are added with the console features.
 */
final class AdminPanelProvider extends PanelProvider
{
    public const SESSION_COOKIE = 'bitflag_staff_session';

    public function register(): void
    {
        parent::register();

        $this->useStaffSessionOnAdminHost();
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->domain(Config::string('bitflag.hosts.admin'))
            ->path('')
            ->login()
            ->authGuard('staff')
            ->brandName('BitFlag Support')
            ->colors(['primary' => Color::Violet])
            ->pages([Dashboard::class])
            ->widgets([])
            ->discoverResources(in: app_path('Admin/Resources'), for: 'App\\Admin\\Resources')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }

    /**
     * Every request to the admin host uses the staff session cookie and the staff guard, whichever route handles it
     * (the Filament panel, the Horizon dashboard or the admin routes). Cookies are also host-only, so the two sessions
     * could not meet even with the same name; the distinct name keeps them apart in tools that show cookies together.
     */
    private function useStaffSessionOnAdminHost(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        if ($this->app->make('request')->getHost() === Config::string('bitflag.hosts.admin')) {
            config(['session.cookie' => self::SESSION_COOKIE, 'auth.defaults.guard' => 'staff']);
        }
    }
}
