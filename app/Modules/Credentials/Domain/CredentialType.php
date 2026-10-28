<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * The kinds of credential. Stored values never change.
 */
enum CredentialType: string
{
    case Server = 'server';
    case Browser = 'browser';
    case Management = 'management';
    case Personal = 'personal';

    /** The part of the secret after `bf_`, which tells the kinds apart without a lookup. */
    public function marker(): string
    {
        return match ($this) {
            self::Server => 'srv',
            self::Browser => 'pub',
            self::Management => 'mgt',
            self::Personal => 'pat',
        };
    }

    public static function fromMarker(string $marker): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->marker() === $marker) {
                return $type;
            }
        }

        return null;
    }

    /** True for the keys SDKs use to fetch snapshots. */
    public function isSdkKey(): bool
    {
        return $this === self::Server || $this === self::Browser;
    }

    /** True for the credentials tied to one environment. */
    public function isEnvironmentBound(): bool
    {
        return $this !== self::Personal;
    }
}
