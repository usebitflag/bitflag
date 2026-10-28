<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * The short tag in an environment key's display prefix, such as `prod` in `bf_srv_prod_`. It is only a label: nothing reads
 * it to decide what a key may do.
 */
final class EnvironmentTag
{
    public static function for(string $environmentKey): string
    {
        $key = strtolower($environmentKey);

        return match ($key) {
            'development' => 'dev',
            'staging' => 'stg',
            'production' => 'prod',
            default => self::fromKey($key),
        };
    }

    private static function fromKey(string $key): string
    {
        $tag = substr((string) preg_replace('/[^a-z0-9]/', '', $key), 0, 4);

        return $tag === '' ? 'env' : $tag;
    }
}
