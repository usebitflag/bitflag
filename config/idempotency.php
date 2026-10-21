<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotency records
    |--------------------------------------------------------------------------
    |
    | A retried request that reuses its idempotency key returns the stored result instead of repeating the operation.
    | Records expire after ttl_hours and idempotency:prune removes them hourly. A duplicate that arrives while the first
    | request is still running waits at most lock_wait_seconds for it, then gets IDEMPOTENCY_IN_PROGRESS.
    |
    */

    'ttl_hours' => 24,

    'lock_wait_seconds' => 3,

];
