<?php

declare(strict_types=1);

namespace App\Shared\Time;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;

/**
 * The application clock. Actions take a ClockInterface instead of calling now(), so tests and the end-to-end suite can
 * freeze time. It follows Carbon's test time, so Carbon::setTestNow() moves it too.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Carbon::now('UTC')->toDateTimeImmutable();
    }
}
