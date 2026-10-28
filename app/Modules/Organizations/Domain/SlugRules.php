<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * What an organization's URL name may be. It leads every portal address, so it must start with a letter, stay short, and
 * never collide with an application path.
 */
final class SlugRules
{
    public const PATTERN = '/^[a-z][a-z0-9-]{2,39}$/';

    public const RESERVED_PREFIX = 'bf-';

    /** Paths the application itself uses at the root, plus words that would mislead. */
    public const RESERVED = [
        'up', 'horizon', 'signin', 'signout', 'login', 'logout', 'scope', 'no-access',
        'signup', 'onboarding', 'forgot-password', 'reset-password', 'email', 'password', 'organizations',
        'two-factor-challenge', 'invitations', 'account', 'settings', 'billing', 'docs', 'status', 'help',
        'api', 'sdk', 'admin', 'www', 'app', 'bitflag', 'support', 'security', 'privacy', 'terms', 'pricing',
    ];

    public const TOO_SHORT_OR_LONG = 'Use 3 to 40 characters.';

    public const BAD_CHARACTERS = 'Start with a letter, then use letters, numbers and dashes.';

    public const TAKEN_WORD = 'That name is reserved.';

    public const TAKEN = 'That name is taken.';

    /**
     * Why the slug cannot be used, or null if its form is acceptable. Whether it is taken is a separate question.
     */
    public static function problem(string $slug): ?string
    {
        $length = strlen($slug);

        if ($length < 3 || $length > 40) {
            return self::TOO_SHORT_OR_LONG;
        }

        if (preg_match(self::PATTERN, $slug) !== 1) {
            return self::BAD_CHARACTERS;
        }

        if (in_array($slug, self::RESERVED, true) || str_starts_with($slug, self::RESERVED_PREFIX)) {
            return self::TAKEN_WORD;
        }

        return null;
    }

    /**
     * A suggestion made from a name: lower case, words joined with dashes, trimmed to fit.
     */
    public static function suggest(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $slug = (string) preg_replace('/^[^a-z]+/', '', $slug);

        return rtrim(substr($slug, 0, 40), '-');
    }

    /**
     * The route constraint for an organization segment: the form above, and none of the reserved words.
     */
    public static function routePattern(): string
    {
        // The word must be followed by a slash or the end of the address, because the pattern is placed inside a longer
        // address pattern and a bare end-of-string anchor would only ever match single-segment addresses.
        return '(?!(?:'.implode('|', array_map('preg_quote', self::RESERVED)).')(?:/|$))(?!'.self::RESERVED_PREFIX.')[a-z][a-z0-9-]{1,38}';
    }
}
