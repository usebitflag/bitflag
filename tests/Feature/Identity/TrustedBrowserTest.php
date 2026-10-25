<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\GenerateRecoveryCodes;
use App\Modules\Identity\Models\TrustedBrowser;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Queries\TwoFactorStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    enroll_two_factor($this->user);
});

function trust_this_browser(): string
{
    test()->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    $response = test()->post('/two-factor-challenge', ['code' => totp_now(), 'trust' => true]);

    $cookie = $response->getCookie('bf_tb');
    expect($cookie)->not->toBeNull();
    test()->post('/signout');

    return (string) $cookie->getValue();
}

function password_only(string $cookie): TestResponse
{
    return test()->withCookie('bf_tb', $cookie)->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
}

it('sets a cookie only when asked to trust the browser', function () {
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    $without = $this->post('/two-factor-challenge', ['code' => totp_now()]);

    expect($without->getCookie('bf_tb'))->toBeNull()
        ->and(TrustedBrowser::query()->count())->toBe(0);
});

it('sets a secure cookie that lasts thirty days and is not readable by scripts', function () {
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    $response = $this->post('/two-factor-challenge', ['code' => totp_now(), 'trust' => true]);

    $cookie = $response->getCookie('bf_tb', false);

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->addMinute()->getTimestamp());
});

it('stores only a hash of the secret, with a label and thirty days', function () {
    $cookie = trust_this_browser();
    [$id, $secret] = explode('.', $cookie);
    $row = TrustedBrowser::query()->findOrFail($id);

    expect(abs($row->expires_at->diffInSeconds(now()->addDays(30))))->toBeLessThan(5)
        ->and(DB::table('trusted_browsers')->get()->map(fn ($r) => json_encode((array) $r, JSON_INVALID_UTF8_SUBSTITUTE))->implode(''))->not->toContain($secret)
        ->and(strlen($row->token_hash))->toBe(32);
});

it('skips the code on the next password sign-in from that browser', function () {
    $cookie = trust_this_browser();

    password_only($cookie)->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
});

it('still asks for the code from a browser that is not trusted', function () {
    trust_this_browser();

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/two-factor-challenge');

    $this->assertGuest('web');
});

it('does not accept a cookie with a wrong secret, an unknown id or a bad shape', function (string $change) {
    $cookie = trust_this_browser();
    [$id, $secret] = explode('.', $cookie);
    $bad = match ($change) {
        'secret' => "{$id}.".strrev($secret),
        'id' => strtolower((string) Str::ulid()).".{$secret}",
        'shape' => $secret,
        'dots' => "{$id}.{$secret}.extra",
    };

    password_only($bad)->assertRedirect('/two-factor-challenge');

    $this->assertGuest('web');
})->with(['secret', 'id', 'shape', 'dots']);

it('does not accept another person\'s trusted browser', function () {
    $cookie = trust_this_browser();
    $other = User::factory()->create(['email' => 'jon@acme.dev', 'password' => 'correct horse']);
    enroll_two_factor($other);

    $this->withCookie('bf_tb', $cookie)->post('/signin', ['email' => 'jon@acme.dev', 'password' => 'correct horse'])->assertRedirect('/two-factor-challenge');

    $this->assertGuest('web');
});

it('stops working after thirty days', function () {
    $cookie = trust_this_browser();
    $this->travel(31)->days();

    password_only($cookie)->assertRedirect('/two-factor-challenge');
});

it('still needs the password', function () {
    $cookie = trust_this_browser();

    $this->withCookie('bf_tb', $cookie)->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

it('stops working when revoked, by a reset, a password change or turning two-factor off', function (string $how) {
    $cookie = trust_this_browser();

    match ($how) {
        'reset' => (function () {
            $token = Password::broker()->createToken($this->user);
            $this->post('/reset-password', ['token' => $token, 'user' => 'usr_'.$this->user->id, 'password' => 'a brand new password']);
            $this->post('/signout');
        })->call($this),
        'change' => $this->actingAs($this->user)->put('/account/password', ['current_password' => 'correct horse', 'password' => 'a brand new password']),
        'revoke' => TrustedBrowser::query()->update(['revoked_at' => now()]),
    };
    auth('web')->logout();
    $password = $how === 'revoke' ? 'correct horse' : 'a brand new password';

    $this->withCookie('bf_tb', $cookie)->post('/signin', ['email' => 'maya@acme.dev', 'password' => $password])->assertRedirect('/two-factor-challenge');
})->with(['reset', 'change', 'revoke']);

it('counts a trusted browser as having two-factor', function () {
    $cookie = trust_this_browser();

    password_only($cookie);

    expect(app(TwoFactorStatus::class)->isEnrolled($this->user->id))->toBeTrue();
});

it('makes ten recovery codes that work after trusting', function () {
    $codes = app(GenerateRecoveryCodes::class)->handle($this->user->id);

    expect($codes)->toHaveCount(10);
});
