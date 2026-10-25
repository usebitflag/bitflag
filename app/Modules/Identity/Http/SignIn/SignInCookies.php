<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The two long-lived cookies that sign-in reads and writes. Both are HttpOnly and SameSite=Lax, and Secure when the
 * request is. Laravel encrypts them, so a value cannot be read or forged in the browser.
 */
final class SignInCookies
{
    /** A browser that skipped the second factor once and may again (`{id}.{secret}`). */
    public const TRUSTED_BROWSER = 'bf_tb';

    /** A random value naming this device, for the "new sign-in" notice. */
    public const DEVICE = 'bf_dev';

    private const TRUSTED_MINUTES = 60 * 24 * 30;

    private const DEVICE_MINUTES = 60 * 24 * 400;

    public static function trustedBrowser(Request $request, string $value): Cookie
    {
        return cookie(self::TRUSTED_BROWSER, $value, self::TRUSTED_MINUTES, '/', null, $request->isSecure(), true, false, 'lax');
    }

    public static function device(Request $request, string $value): Cookie
    {
        return cookie(self::DEVICE, $value, self::DEVICE_MINUTES, '/', null, $request->isSecure(), true, false, 'lax');
    }

    public static function read(Request $request, string $name): ?string
    {
        $value = $request->cookie($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
