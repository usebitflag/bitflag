<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    Carbon::setTestNow('2026-10-25 10:00:00');
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    MfaFactor::query()->create([
        'user_id' => $this->user->id,
        'type' => MfaFactorType::Totp,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);
});

it('does not sign in a person who owes a second factor', function () {
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])
        ->assertRedirect('/two-factor-challenge');

    $this->assertGuest('web');
});

it('keeps only who is pending, for ten minutes, and never the password', function () {
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    expect(session('login.pending'))->toBe([
        'user_id' => $this->user->id,
        'remember' => false,
        'expires_at' => '2026-10-25T10:10:00+00:00',
        'attempts' => 0,
    ])->and(json_encode(session()->all()))->not->toContain('correct horse');
});

it('answers a pending JSON sign-in without a redirect', function () {
    $this->postJson('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])
        ->assertOk()
        ->assertExactJson(['two_factor' => true]);
});

it('gives a half-signed-in session the same answer as a guest', function () {
    $guest = $this->get('/');
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    $pending = $this->get('/');

    expect($pending->getStatusCode())->toBe($guest->getStatusCode())
        ->and($pending->headers->get('Location'))->toBe($guest->headers->get('Location'));
    $this->assertGuest('web');
});

it('changes the session id when the password is right', function () {
    $this->get('/signin');
    $before = session()->getId();

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    expect(session()->getId())->not->toBe($before);
});

it('does not count the password step as a finished sign-in', function () {
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    expect(SecurityEvent::query()->where('type', SecurityEventType::SignInSucceeded)->exists())->toBeFalse();
});

it('does not ask for a second factor when the enrollment was never confirmed', function () {
    MfaFactor::query()->where('user_id', $this->user->id)->update(['confirmed_at' => null]);

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
});
