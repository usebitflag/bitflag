<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

beforeEach(function () {
    config(['inertia.testing.ensure_pages_exist' => false]);
    Route::middleware('web')->get('/_probe', fn () => Inertia::render('Probe'));
});

it('sends the baseline security headers', function () {
    $response = $this->get('/_probe');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("default-src 'self'");
});

it('binds the script policy to the nonce used by inline scripts', function () {
    $response = $this->get('/_probe');
    $policy = (string) $response->headers->get('Content-Security-Policy');

    preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $policyNonce);
    preg_match('/<script nonce="([^"]+)">/', (string) $response->getContent(), $scriptNonce);

    expect($policyNonce[1] ?? null)->not->toBeNull()->toBe($scriptNonce[1] ?? 'missing');
});

it('uses a different nonce for each request', function () {
    $nonce = fn () => preg_match("/'nonce-([^']+)'/", (string) $this->get('/_probe')->headers->get('Content-Security-Policy'), $m) ? $m[1] : null;

    expect($nonce())->not->toBe($nonce());
});

it('sends HSTS only on secure requests outside local development', function () {
    $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');

    app()->detectEnvironment(fn () => 'production');
    $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
