<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

/**
 * What a project's key may be. It is the second segment of every portal address and is used by SDKs and the CLI, so it is
 * short, lower case, and never a word the organization pages use.
 */
final class ProjectKeyRules
{
    public const PATTERN = '/^[a-z][a-z0-9-]{1,39}$/';

    /** Words used as the second segment of an organization-level address. */
    public const RESERVED = ['settings', 'members', 'security', 'projects', 'billing', 'general', 'new', 'overview'];

    public const BAD = 'Start with a letter. Use a–z, 0–9 and -';

    public const RESERVED_WORD = 'That key is reserved.';

    public const TAKEN = 'Already used in this organization';

    public static function problem(string $key): ?string
    {
        if (preg_match(self::PATTERN, $key) !== 1) {
            return self::BAD;
        }

        return in_array($key, self::RESERVED, true) ? self::RESERVED_WORD : null;
    }

    public static function suggest(string $name): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $key = (string) preg_replace('/^[^a-z]+/', '', $key);

        return rtrim(substr($key, 0, 40), '-');
    }
}
