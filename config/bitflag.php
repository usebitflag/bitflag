<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Hosts
    |--------------------------------------------------------------------------
    |
    | The portal is served on the app host and the admin console on the admin
    | host. Each host serves only its own routes, and sessions never cross
    | hosts because cookies are host-only.
    |
    */

    'hosts' => [
        'app' => env('APP_HOST', 'localhost'),
        'admin' => env('ADMIN_HOST', 'admin.localhost'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | DemoSeeder builds the sample world used for local development and the
    | end-to-end tests. It refuses to run in production. Every demo person
    | signs in with this password.
    |
    */

    'demo_password' => env('DEMO_PASSWORD', 'password'),

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated addresses or CIDR ranges of the load balancers in front
    | of the application, or * when the platform gives no fixed address. Leave
    | empty when nothing sits in front of it.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

];
