<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * The email domains an organization allows its members and invitees to use. An empty list allows any.
 */
final class AllowedDomains
{
    public const MAX = 20;

    private const DOMAIN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * Lower-cased, without a leading "@", duplicates removed. Returns the cleaned list and the entries that are not domains.
     *
     * @param  list<string>  $domains
     * @return array{domains: list<string>, invalid: list<string>}
     */
    public static function clean(array $domains): array
    {
        $clean = [];
        $invalid = [];

        foreach ($domains as $domain) {
            $normalized = ltrim(strtolower(trim($domain)), '@');

            if ($normalized === '') {
                continue;
            }

            if (preg_match(self::DOMAIN, $normalized) !== 1) {
                $invalid[] = $domain;

                continue;
            }

            $clean[$normalized] = $normalized;
        }

        return ['domains' => array_values($clean), 'invalid' => $invalid];
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function permits(array $allowed, string $email): bool
    {
        if ($allowed === []) {
            return true;
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, $allowed, true);
    }
}
