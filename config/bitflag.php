<?php

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

];
