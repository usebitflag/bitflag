<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Actions\Fortify\AuthenticateUser;
use App\Modules\Identity\Domain\GeoLocator;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\NullGeoLocator;
use App\Modules\Identity\Support\TrackedDatabaseSessionHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = $request->input(Fortify::username());
            $password = $request->input('password');

            return is_string($email) && is_string($password)
                ? $this->app->make(AuthenticateUser::class)->handle($email, $password)
                : null;
        });

        // Five attempts a minute for one address from one network, and thirty a minute from one network whatever the
        // address, so trying many different accounts from one place is slowed too.
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input(Fortify::username());
            $key = Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });
    }
}
