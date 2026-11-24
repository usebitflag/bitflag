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

    /*
    |--------------------------------------------------------------------------
    | Credential peppers
    |--------------------------------------------------------------------------
    |
    | Recovery codes, invitation tokens, trusted-browser tokens and API keys are
    | stored as HMAC-SHA256 under a pepper that never enters the database. Each
    | pepper is 32 random bytes, base64-encoded (`openssl rand -base64 32`).
    | New hashes use the current pepper; while rotating, set the previous id so
    | secrets stored under it still match.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Deletion grace
    |--------------------------------------------------------------------------
    |
    | A deleted project or organization is scheduled first and can be restored
    | until this many days have passed. SDK keys keep working until the purge.
    |
    */

    'lifecycle' => [
        'project_deletion_grace_days' => (int) env('BITFLAG_PROJECT_DELETION_GRACE_DAYS', 7),
        'organization_deletion_grace_days' => (int) env('BITFLAG_ORGANIZATION_DELETION_GRACE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Project exports
    |--------------------------------------------------------------------------
    |
    | Export files are written to a private disk and deleted after 24 hours.
    | Local disk until object storage is chosen.
    |
    */

    'exports' => [
        'disk' => env('BITFLAG_EXPORTS_DISK', 'local'),
    ],

    'credentials' => [
        'current_pepper_id' => (int) env('BITFLAG_CREDENTIAL_PEPPER_CURRENT', 1),
        'previous_pepper_id' => env('BITFLAG_CREDENTIAL_PEPPER_PREVIOUS') !== null
            ? (int) env('BITFLAG_CREDENTIAL_PEPPER_PREVIOUS')
            : null,
        'peppers' => [
            1 => env('BITFLAG_CREDENTIAL_PEPPER_1'),
            2 => env('BITFLAG_CREDENTIAL_PEPPER_2'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Organizations
    |--------------------------------------------------------------------------
    |
    | How many organizations one person may create. It guards against one
    | account filling the system with organizations, not against a team that
    | has several.
    |
    */

    'organizations' => [
        'max_per_user' => (int) env('BITFLAG_MAX_ORGANIZATIONS_PER_USER', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    |
    | Whether the sign-up wizard has a plan step. Billing turns it on when it
    | registers one; until then every organization is on the Free plan.
    |
    */

    'onboarding' => [
        'plan_step' => false,
    ],

];
