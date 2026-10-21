<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * A SHA-256 over the canonical form of a request, so the same request always has the same fingerprint regardless of
 * the order of its keys.
 */
final class RequestFingerprint
{
    /**
     * @param  array<array-key, mixed>  $request
     */
    public static function of(array $request): string
    {
        return hash('sha256', json_encode(self::canonical($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(self::canonical(...), $value);

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
