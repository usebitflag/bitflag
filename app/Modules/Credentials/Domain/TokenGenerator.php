<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * Makes and checks credential secrets.
 *
 *   bf_srv_prod_<30 random base62><6 base62 checksum>      environment credentials
 *   bf_pat_<30 random base62><6 base62 checksum>           personal tokens
 *
 * The random part carries about 178 bits. The checksum (CRC32 of everything before it) lets the server and SDKs reject a
 * mistyped or malformed key without a lookup, and makes secret scanning reliable. It is not a secret and adds no strength.
 */
final class TokenGenerator
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public const RANDOM_LENGTH = 30;

    public const CHECKSUM_LENGTH = 6;

    public const ENVIRONMENT_PATTERN = '/^bf_(srv|pub|mgt)_([a-z0-9]{1,4})_[A-Za-z0-9]{36}$/';

    public const PERSONAL_PATTERN = '/^bf_pat_[A-Za-z0-9]{36}$/';

    public function generate(CredentialType $type, ?string $tag = null): GeneratedToken
    {
        $prefix = $type === CredentialType::Personal
            ? 'bf_pat_'
            : 'bf_'.$type->marker().'_'.($tag ?? 'env').'_';

        $body = $prefix.self::randomBase62(self::RANDOM_LENGTH);
        $secret = $body.self::checksum($body);

        return new GeneratedToken($secret, $type, $prefix, substr($secret, -4));
    }

    /**
     * What a presented string is, or null when it is not shaped like a credential or its checksum is wrong.
     */
    public static function parse(string $presented): ?ParsedToken
    {
        if (preg_match(self::ENVIRONMENT_PATTERN, $presented, $parts) === 1) {
            $type = CredentialType::fromMarker($parts[1]);
            $tag = $parts[2];
        } elseif (preg_match(self::PERSONAL_PATTERN, $presented) === 1) {
            $type = CredentialType::Personal;
            $tag = null;
        } else {
            return null;
        }

        if ($type === null) {
            return null;
        }

        $body = substr($presented, 0, -self::CHECKSUM_LENGTH);

        return hash_equals(self::checksum($body), substr($presented, -self::CHECKSUM_LENGTH)) ? new ParsedToken($type, $tag) : null;
    }

    /**
     * The same token with its random part replaced, for finding a secret that was copied in pieces. Not used to authorize.
     */
    public static function randomPart(string $secret): string
    {
        return substr($secret, -(self::RANDOM_LENGTH + self::CHECKSUM_LENGTH), self::RANDOM_LENGTH);
    }

    public static function checksum(string $body): string
    {
        $value = crc32($body);
        $digits = '';

        do {
            $digits = self::ALPHABET[$value % 62].$digits;
            $value = intdiv($value, 62);
        } while ($value > 0);

        return str_pad($digits, self::CHECKSUM_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Random base62 characters without modulo bias: a byte is used only if it falls in the largest multiple of 62 below 256.
     */
    public static function randomBase62(int $length): string
    {
        $limit = 248;
        $out = '';

        while (strlen($out) < $length) {
            foreach (str_split(random_bytes($length * 2)) as $char) {
                $byte = ord($char);

                if ($byte < $limit) {
                    $out .= self::ALPHABET[$byte % 62];

                    if (strlen($out) === $length) {
                        break;
                    }
                }
            }
        }

        return $out;
    }
}
