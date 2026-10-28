<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Support;

use Illuminate\Support\Facades\Redis;
use LogicException;

/**
 * The phpredis client behind the default connection, for the commands that need phpredis's own argument forms (SET with
 * NX and EX, ZADD with GT, EVAL).
 */
final class RedisClient
{
    public static function get(): \Redis
    {
        $client = Redis::connection()->client();

        if (! $client instanceof \Redis) {
            throw new LogicException('Credential caching needs the phpredis client.');
        }

        return $client;
    }
}
