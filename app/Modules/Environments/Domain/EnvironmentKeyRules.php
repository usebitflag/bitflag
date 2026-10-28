<?php

declare(strict_types=1);

namespace App\Modules\Environments\Domain;

/**
 * What an environment's key may be. It is the fourth segment of every project address and the tag in its credentials'
 * display prefix, so it is short and lower case. It can change only until the environment's first publication.
 */
final class EnvironmentKeyRules
{
    public const PATTERN = '/^[a-z][a-z0-9-]{0,39}$/';

    public const BAD = 'Start with a letter. Use a–z, 0–9 and -';

    public const TAKEN = 'Already used in this project';

    public const LOCKED = 'The key is fixed after the first publish.';

    public const NAME = 'Enter a name of up to 40 characters.';

    public static function problem(string $key): ?string
    {
        return preg_match(self::PATTERN, $key) === 1 ? null : self::BAD;
    }

    public static function name(string $name): ?string
    {
        return $name === '' || mb_strlen($name) > 40 ? self::NAME : null;
    }

    public static function suggest(string $name): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $key = (string) preg_replace('/^[^a-z]+/', '', $key);

        return rtrim(substr($key, 0, 40), '-');
    }
}
