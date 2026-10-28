<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

const LOCKED = 'Too many attempts. Try again in 15 minutes.';
const MISMATCH = "That email and password don't match. Check them and try again.";

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
});

function attempt(string $email, string $password = 'wrong password'): string
{
    $response = test()->from('/signin')->post('/signin', ['email' => $email, 'password' => $password]);
    $response->assertSessionHasErrors('email');

    return session('errors')->first('email');
}

it('locks an address after five failures, for a known and an unknown address alike', function (string $email) {
    foreach (range(1, 5) as $n) {
        expect(attempt($email))->toBe(MISMATCH);
    }

    expect(attempt($email))->toBe(LOCKED)
        ->and(attempt($email, 'correct horse'))->toBe(LOCKED);
    $this->assertGuest('web');
})->with(['known address' => ['maya@acme.dev'], 'unknown address' => ['nobody@acme.dev']]);

it('locks the same address however its letters are cased', function () {
    foreach (range(1, 5) as $n) {
        attempt('maya@acme.dev');
    }

    expect(attempt('MAYA@Acme.dev', 'correct horse'))->toBe(LOCKED);
});

it('does not lock other addresses', function () {
    foreach (range(1, 5) as $n) {
        attempt('nobody@acme.dev');
    }

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/');
});

it('lets the person in again after fifteen minutes', function () {
    foreach (range(1, 5) as $n) {
        attempt('maya@acme.dev');
    }
    expect(attempt('maya@acme.dev', 'correct horse'))->toBe(LOCKED);

    $this->travel(16)->minutes();

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/');
});

it('counts failures in a window and forgets them after a good sign-in', function () {
    foreach (range(1, 4) as $n) {
        attempt('maya@acme.dev');
    }
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/');
    $this->post('/signout');

    foreach (range(1, 4) as $n) {
        expect(attempt('maya@acme.dev'))->toBe(MISMATCH);
    }
});

it('holds the answer back from the third failure, one second longer each time', function () {
    foreach (range(1, 5) as $n) {
        attempt('maya@acme.dev');
    }

    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
});

it('answers a locked unknown address exactly like a locked known one', function () {
    foreach (['maya@acme.dev', 'nobody@acme.dev'] as $email) {
        foreach (range(1, 5) as $n) {
            attempt($email);
        }
    }

    $known = $this->from('/signin')->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'x']);
    $unknown = $this->from('/signin')->post('/signin', ['email' => 'nobody@acme.dev', 'password' => 'x']);

    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'));
});

it('logs sign-ins in the security log, with a hash for unknown addresses', function () {
    attempt('maya@acme.dev');
    attempt('nobody@acme.dev');
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    $events = SecurityEvent::query()->orderBy('id')->get();

    expect($events->pluck('type')->all())->toEqual([SecurityEventType::SignInFailed, SecurityEventType::SignInFailed, SecurityEventType::SignInSucceeded])
        ->and($events[0]->user_id)->toBe($this->user->id)
        ->and($events[0]->email_hash)->toBeNull()
        ->and($events[1]->user_id)->toBeNull()
        ->and(strlen((string) $events[1]->email_hash))->toBe(32)
        ->and($events[1]->email_hash)->not->toBe('nobody@acme.dev')
        ->and($events[2]->ip_address)->toBe('127.0.0.1');
});

it('logs the lock once, when it happens', function () {
    foreach (range(1, 8) as $n) {
        attempt('maya@acme.dev');
    }

    expect(SecurityEvent::query()->where('type', SecurityEventType::SignInLocked)->count())->toBe(1)
        ->and(SecurityEvent::query()->where('type', SecurityEventType::SignInFailed)->count())->toBe(5);
});

it('never stores the address or password of a failed attempt', function () {
    attempt('nobody@acme.dev', 'hunter2-secret');

    $row = json_encode(SecurityEvent::query()->firstOrFail()->getAttributes(), JSON_INVALID_UTF8_SUBSTITUTE);

    expect($row)->not->toContain('nobody@acme.dev')->not->toContain('hunter2');
});
