<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | End-to-end test routes
    |--------------------------------------------------------------------------
    |
    | routes/testing.php loads only when e2e is true and the application runs
    | in the local or testing environment. Requests must also carry the token.
    |
    */

    'e2e' => (bool) env('TESTING_E2E', false),

    'token' => env('TESTING_E2E_TOKEN'),

];
