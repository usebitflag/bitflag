<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Recovery codes: ten characters of base32 (about 50 bits) shown as `xxxxx-xxxxx`. They are shown once, stored only as
 * hashes, and each works once.
 */
final class RecoveryCodes
{
    public const COUNT = 10;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    /**
     * @return list<string>
     */
    public static function generate(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $raw = '';
            foreach (str_split(random_bytes(10)) as $byte) {
                $raw .= self::ALPHABET[ord($byte) & 31];
            }

            $code = substr($raw, 0, 5).'-'.substr($raw, 5, 5);

            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * What is hashed and compared: lower case, without the dash or spaces, so a code typed either way matches.
     */
    public static function normalize(string $code): string
    {
        return str_replace(['-', ' '], '', mb_strtolower(trim($code)));
    }

    public static function looksLikeCode(string $code): bool
    {
        return preg_match('/^['.self::ALPHABET.']{10}$/', self::normalize($code)) === 1;
    }
}
