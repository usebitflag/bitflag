<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * The limits on inviting people.
 */
final class InvitationRules
{
    public const VALID_DAYS = 7;

    public const MAX_EMAILS_PER_REQUEST = 20;

    public const MAX_PER_ORGANIZATION_PER_DAY = 200;

    public const DOMAIN_BLOCKED = 'Invitations to other domains are blocked';

    public const ALREADY_MEMBER = 'Already a member';

    public const ALREADY_INVITED = 'Already invited';

    public const INVALID_EMAIL = 'Not a valid email address';

    public const DAILY_LIMIT = 'Too many invitations today';

    /**
     * Splits what a person typed or pasted into addresses: on commas, semicolons, spaces and line breaks, lower-cased,
     * without duplicates.
     *
     * @return list<string>
     */
    public static function parseEmails(string $text): array
    {
        $emails = [];

        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            $emails[strtolower(trim($candidate, "<>\"'"))] = true;
        }

        return array_keys($emails);
    }

    public static function isValidEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * "j•••@acme.dev": enough for the owner of the address to recognize it, little for anyone else.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
