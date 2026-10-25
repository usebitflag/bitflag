<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Domain\GeoLocator;
use App\Modules\Identity\Http\SignIn\CompleteSignIn;
use App\Modules\Identity\Http\SignIn\RedirectIfTwoFactorRequired;
use App\Modules\Identity\Http\SignIn\ValidateCredentials;
use App\Modules\Identity\Support\NullGeoLocator;
use App\Modules\Identity\Support\TrackedDatabaseSessionHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

/**
 * Registers the Identity module: accounts, authentication and sessions.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The portal's own routes (routes/web/identity.php) replace Fortify's default ones.
        Fortify::ignoreRoutes();

        // No GeoIP source is chosen yet, so sessions show an unknown location.
        $this->app->bind(GeoLocator::class, NullGeoLocator::class);
    }

    public function boot(): void
    {
        // The "database" session driver, plus device and location details for the sessions list.
        Session::extend('database', function (Application $app): TrackedDatabaseSessionHandler {
            $connection = config('session.connection');

            return new TrackedDatabaseSessionHandler(
                DB::connection(is_string($connection) ? $connection : null),
                config()->string('session.table'),
                config()->integer('session.lifetime'),
                $app,
                $app->make(GeoLocator::class),
            );
        });

        // A correct password does not sign anyone in: the last stage does, and only when no second factor is owed.
        Fortify::authenticateThrough(fn (): array => [
            ValidateCredentials::class,
            RedirectIfTwoFactorRequired::class,
            CompleteSignIn::class,
            PrepareAuthenticatedSession::class,
        ]);

        // Thirty attempts a minute from one network whatever the address, so trying many accounts from one place is
        // slowed. Guessing at one address is limited by the sign-in lock, which also covers addresses with no account.
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(30)->by('ip|'.$request->ip()));
    }
}
