<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use RuntimeException;

/**
 * Raises an unhandled error so a spec can check the 500 screen and its request ID.
 */
final class FailController
{
    public function __invoke(): never
    {
        throw new RuntimeException('Forced failure for the end-to-end suite.');
    }
}
