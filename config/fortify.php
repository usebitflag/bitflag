<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Fortify
|--------------------------------------------------------------------------
|
| Fortify supplies the sign-in pipeline only. The routes are registered by the Identity module on the portal host, and
| the screens are Inertia pages. Sign-up, verification, password reset and two-factor are added by their own features.
| Two-factor factors live in mfa_factors, not in Fortify's users columns.
|
*/

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    'limiters' => [
        'login' => 'login',
    ],

    'views' => false,

    'features' => [
        // None yet: signing in and out need no feature flag.
    ],

];
