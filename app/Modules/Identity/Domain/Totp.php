<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Time-based one-time passwords (RFC 6238) as authenticator apps compute them: HMAC-SHA1, six digits, thirty-second steps.
 * A code is accepted one step either side of now, and never twice: the caller passes the last step it accepted and stores
 * the one returned.
 */
final class Totp
{
    public const STEP_SECONDS = 30;

    public const DIGITS = 6;

    public const WINDOW = 1;

    public const SECRET_BYTES = 20;

    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(self::SECRET_BYTES));
    }

    public static function timestep(int $timestamp): int
    {
        return intdiv($timestamp, self::STEP_SECONDS);
    }

    public static function code(string $secret, int $timestep): string
    {
        $hash = hash_hmac('sha1', pack('J', $timestep), Base32::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step the code matches, or null when it matches none within the window or only a step already used.
     */
    public static function verify(string $secret, string $code, int $timestamp, ?int $lastUsedTimestep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $now = self::timestep($timestamp);
        $matched = null;

        // Every step in the window is compared, so the time taken does not say which one matched.
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if (hash_equals(self::code($secret, $step), $code) && ($lastUsedTimestep === null || $step > $lastUsedTimestep)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer = 'BitFlag'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::STEP_SECONDS;
    }
}
