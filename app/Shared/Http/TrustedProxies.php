<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Tells the application which proxies sit in front of it, so it reads the client address and the original scheme from
 * their forwarding headers. Without it, behind a load balancer every request looks like plain HTTP from the balancer's
 * own address, which breaks secure cookies, URL generation and per-address rate limits.
 *
 * The setting is a comma-separated list of addresses or CIDR ranges, or `*` for a platform that gives no fixed address.
 * Empty trusts no proxy.
 */
final class TrustedProxies
{
    public static function apply(?string $setting): void
    {
        $setting = trim($setting ?? '');

        if ($setting === '') {
            return;
        }

        TrustProxies::at($setting === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $setting)))));
    }
}
