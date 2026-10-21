<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Actions\Fortify\AuthenticateUser;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
    }

    public function boot(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = $request->input(Fortify::username());
            $password = $request->input('password');

            return is_string($email) && is_string($password)
                ? $this->app->make(AuthenticateUser::class)->handle($email, $password)
                : null;
        });

        // Five attempts a minute for one address from one network.
        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input(Fortify::username());
            $key = Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });
    }
}
