<?php

declare(strict_types=1);

namespace App\Shared\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Timestamps are stored with microsecond precision (timestamp(6)) in UTC. Laravel's MySQL date format drops the
 * fraction, which would make ordering by time ambiguous.
 *
 * @mixin Model
 */
trait UsesMicrosecondTimestamps
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }
}
