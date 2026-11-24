<?php

declare(strict_types=1);

namespace App\Modules\Environments\Domain;

/**
 * Which browser origins an environment may list. An origin is `scheme://host[:port]` with no path, query or wildcard; the
 * scheme is https except for localhost and 127.0.0.1. Origins limit which pages may use a browser key; they do not hide
 * anything.
 */
final class OriginRules
{
    public const MAX = 20;

    public const DUPLICATE = 'Already in the list';

    public const TOO_MANY = 'Use at most 20 origins.';

    public const SHAPE = 'Use scheme://host, for example https://app.example.com. No path, query or wildcard.';

    public const HTTPS = 'Use https. Plain http is only for localhost.';

    /**
     * The normalized origin, or null when the text is not a usable origin. Lower-cases the scheme and host, drops the default
     * port and a trailing slash.
     */
    public static function normalize(string $origin): ?string
    {
        $origin = rtrim(trim($origin), '/');

        if (preg_match('#^(https?)://([^/?\#:@*\s]+)(?::(\d{1,5}))?$#i', $origin, $parts) !== 1) {
            return null;
        }

        $scheme = strtolower($parts[1]);
        $host = strtolower($parts[2]);
        $port = $parts[3] ?? '';

        if (str_contains($host, '..') || str_starts_with($host, '.') || str_ends_with($host, '.')) {
            return null;
        }

        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            return null;
        }

        if (($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80')) {
            $port = '';
        }

        return $scheme.'://'.$host.($port === '' ? '' : ':'.$port);
    }

    /**
     * Why the origin cannot be used, or null if it can.
     */
    public static function problem(string $origin): ?string
    {
        $normalized = self::normalize($origin);

        if ($normalized === null) {
            return self::SHAPE;
        }

        $local = in_array(parse_url($normalized, PHP_URL_HOST), ['localhost', '127.0.0.1'], true);

        return str_starts_with($normalized, 'http://') && ! $local ? self::HTTPS : null;
    }

    /**
     * The first thing wrong with a list of origins as [index, message], or null when the whole list is usable. A list over
     * the limit reports index -1.
     *
     * @param  list<string>  $origins
     * @return array{0: int, 1: string}|null
     */
    public static function firstProblem(array $origins): ?array
    {
        if (count($origins) > self::MAX) {
            return [-1, self::TOO_MANY];
        }

        $seen = [];

        foreach ($origins as $index => $origin) {
            $problem = self::problem($origin);

            if ($problem !== null) {
                return [$index, $problem];
            }

            $normalized = (string) self::normalize($origin);

            if (isset($seen[$normalized])) {
                return [$index, self::DUPLICATE];
            }

            $seen[$normalized] = true;
        }

        return null;
    }

    /**
     * The normalized origins of a list that has no problem.
     *
     * @param  list<string>  $origins
     * @return list<string>
     */
    public static function normalizeAll(array $origins): array
    {
        $normalized = [];

        foreach ($origins as $origin) {
            $normalized[] = (string) self::normalize($origin);
        }

        return $normalized;
    }
}
