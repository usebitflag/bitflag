<?php

declare(strict_types=1);

use App\Shared\Http\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

function forwarded_scheme(string $proxyAddress): string
{
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $proxyAddress, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);

    return (new TrustProxies)->handle($request, fn (Request $r) => response($r->isSecure() ? 'https '.$r->ip() : 'http '.$r->ip()))->getContent();
}

afterEach(fn () => TrustProxies::flushState());

it('ignores forwarding headers when no proxy is trusted', function (?string $setting) {
    TrustedProxies::apply($setting);

    expect(forwarded_scheme('10.0.0.1'))->toBe('http 10.0.0.1');
})->with([null, '', '   ']);

it('reads the original scheme and client address from a listed proxy', function () {
    TrustedProxies::apply('10.0.0.1, 10.0.0.2');

    expect(forwarded_scheme('10.0.0.2'))->toBe('https 203.0.113.9')
        ->and(forwarded_scheme('192.0.2.50'))->toBe('http 192.0.2.50');
});

it('trusts any proxy when told to', function () {
    TrustedProxies::apply('*');

    expect(forwarded_scheme('198.51.100.7'))->toBe('https 203.0.113.9');
});
