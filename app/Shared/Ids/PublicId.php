<?php

declare(strict_types=1);

namespace App\Shared\Ids;

use App\Shared\Errors\ResourceNotFound;

/**
 * Public identifiers are the type prefix followed by the lowercase ULID primary key. They are derived, never stored.
 */
final class PublicId
{
    /** Crockford base32 in lowercase (no i, l, o or u); the first character is at most 7 so the value fits 128 bits. */
    private const ULID = '/^[0-7][0-9a-hjkmnp-tv-z]{25}$/';

    public static function encode(IdPrefix $prefix, string $ulid): string
    {
        if (preg_match(self::ULID, $ulid) !== 1) {
            throw new \InvalidArgumentException('Expected a lowercase ULID.');
        }

        return $prefix->value.$ulid;
    }

    /**
     * Returns the ULID inside a public identifier of the expected type.
     *
     * @throws ResourceNotFound when the prefix is wrong or the rest is not a ULID, so a bad identifier is a 404 and
     *                          never a server error.
     */
    public static function decode(IdPrefix $expected, string $publicId): string
    {
        if (! str_starts_with($publicId, $expected->value)) {
            throw new ResourceNotFound;
        }

        $ulid = substr($publicId, strlen($expected->value));

        if (preg_match(self::ULID, $ulid) !== 1) {
            throw new ResourceNotFound;
        }

        return $ulid;
    }

    /**
     * Whether the value is a well-formed public identifier of the given type.
     */
    public static function isValid(IdPrefix $expected, string $publicId): bool
    {
        try {
            self::decode($expected, $publicId);
        } catch (ResourceNotFound) {
            return false;
        }

        return true;
    }
}
