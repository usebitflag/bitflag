<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Domain\CommonPasswords;
use App\Modules\Identity\Domain\GeoLocator;
use App\Modules\Identity\Domain\OrganizationMfaPolicy;
use App\Modules\Identity\Http\SignIn\CompleteSignIn;
use App\Modules\Identity\Http\SignIn\RedirectIfTwoFactorRequired;
use App\Modules\Identity\Http\SignIn\ValidateCredentials;
use App\Modules\Identity\Jobs\SendAccountEmail;
use App\Modules\Identity\Support\BundledCommonPasswords;
use App\Modules\Identity\Support\NoOrganizationMfaPolicy;
use App\Modules\Identity\Support\NullGeoLocator;
use App\Modules\Identity\Support\TrackedDatabaseSessionHandler;
use App\Shared\Outbox\ConsumerRegistry;
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

        // No organization can require two-factor yet.
        $this->app->bind(OrganizationMfaPolicy::class, NoOrganizationMfaPolicy::class);

        $this->app->singleton(CommonPasswords::class, fn (): CommonPasswords => new BundledCommonPasswords(resource_path('security/common-passwords.txt')));
    }

    public function boot(): void
    {
        $this->app->make(ConsumerRegistry::class)->register(SendAccountEmail::class);

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
        // Five sign-ups an hour from one network and three an hour for one address, so the same address cannot be used to
        // probe or to flood someone's inbox.
        RateLimiter::for('signup', function (Request $request): array {
            $email = $request->input('email');

            return [
                Limit::perHour(5)->by('signup-ip|'.$request->ip()),
                Limit::perHour(3)->by('signup-email|'.hash('sha256', mb_strtolower(is_string($email) ? trim($email) : ''))),
            ];
        });
        // Three reset emails an hour for one address and ten an hour from one network.
        RateLimiter::for('password-reset', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? $email : $request->session()->get('reset.email');

            return [
                Limit::perHour(10)->by('reset-ip|'.$request->ip()),
                Limit::perHour(3)->by('reset-email|'.hash('sha256', mb_strtolower(is_string($email) ? trim($email) : ''))),
            ];
        });
        RateLimiter::for('password-check', fn (Request $request): Limit => Limit::perMinute(60)->by('password-check|'.$request->ip()));
        RateLimiter::for('verification', function (Request $request): Limit {
            $id = $request->user('web')?->getAuthIdentifier();

            return Limit::perMinute(1)->by('verification|'.(is_string($id) ? $id : $request->ip()));
        });

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(30)->by('ip|'.$request->ip()));
    }
}
