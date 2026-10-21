<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | outbox:prune deletes a message, and its receipts, once the message was dispatched and every receipt succeeded
    | more than retention_days ago. A message with any receipt that is pending, processing, failed or exhausted is
    | never deleted, so nothing that still needs delivery or a person's replay disappears. Messages carry identifiers
    | and versions only, so pruning delivered ones is housekeeping, not a change to the audit history.
    |
    */

    'retention_days' => (int) env('OUTBOX_RETENTION_DAYS', 14),

    'prune_batch' => 1000,

];
