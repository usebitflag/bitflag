<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    $this->codes = enroll_two_factor($this->user);
});

function password_step(): TestResponse
{
    return test()->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
}

function answer(string $code, array $extra = []): TestResponse
{
    return test()->from('/two-factor-challenge')->post('/two-factor-challenge', ['code' => $code, ...$extra]);
}

it('shows the challenge only to a browser whose password was accepted', function () {
    $this->get('/two-factor-challenge')->assertRedirect('/signin');
    $this->post('/two-factor-challenge', ['code' => totp_now()])->assertRedirect('/signin');

    password_step();

    $this->get('/two-factor-challenge')->assertOk()->assertInertia(fn ($page) => $page
        ->component('auth/TwoFactorChallenge')->where('maskedEmail', 'ma••@acme.dev')->where('canUseRecovery', true));
});

it('signs in with a code from the app', function () {
    password_step();
    $before = session()->getId();

    answer(totp_now())->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
    expect(session()->getId())->not->toBe($before)
        ->and(session('login.pending'))->toBeNull()
        ->and(SecurityEvent::query()->where('type', SecurityEventType::SignInSucceeded)->count())->toBe(1);
});

it('accepts a code from the step before or after', function (int $steps) {
    password_step();

    answer(totp_now($steps))->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
})->with([-1, 1]);

it('refuses a code from two steps away', function () {
    password_step();

    answer(totp_now(2))->assertSessionHasErrors('code');

    $this->assertGuest('web');
});

it('refuses a code that was already used, even for another sign-in', function () {
    password_step();
    answer(totp_now())->assertRedirect('/');
    $this->post('/signout');

    password_step();
    answer(totp_now())->assertSessionHasErrors('code');

    $this->assertGuest('web');
});

it('lets only one of two requests with the same code succeed', function () {
    password_step();
    $first = answer(totp_now());
    $first->assertRedirect('/');
    $this->post('/signout');
    password_step();

    expect(MfaFactor::query()->firstOrFail()->last_used_timestep)->not->toBeNull();
    answer(totp_now())->assertSessionHasErrors('code');
});

it('ends the attempt after five wrong codes, and the next try starts from the password', function () {
    password_step();

    foreach (range(1, 4) as $n) {
        answer('000000')->assertSessionHasErrors('code');
    }
    $last = answer('000000');

    $last->assertRedirect('/signin')->assertSessionHasErrors('email');
    expect(session('login.pending'))->toBeNull();
    $this->get('/two-factor-challenge')->assertRedirect('/signin');
    $this->assertGuest('web');
});

it('counts wrong codes toward the same lock as wrong passwords', function () {
    foreach (range(1, 2) as $n) {
        $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'wrong']);
    }
    password_step();
    foreach (range(1, 3) as $n) {
        answer('000000');
    }

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe('Too many attempts. Try again in 15 minutes.')
        ->and(SecurityEvent::query()->where('type', SecurityEventType::MfaChallengeFailed)->count())->toBe(3)
        ->and(SecurityEvent::query()->where('type', SecurityEventType::SignInLocked)->count())->toBe(1);
});

it('lets the pending sign-in lapse after ten minutes', function () {
    password_step();
    $this->travel(11)->minutes();

    $this->get('/two-factor-challenge')->assertRedirect('/signin');
    answer(totp_now())->assertRedirect('/signin');

    $this->assertGuest('web');
});

it('signs in with a recovery code, once, and tells the person how many are left', function () {
    password_step();

    answer($this->codes[0])->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
    $mail = OutboxMessageRecord::query()->where('payload->template', 'recovery_code_used')->firstOrFail();

    expect($mail->payload['details'])->toBe(['codes_left' => 9])
        ->and(MfaRecoveryCode::query()->whereNull('used_at')->count())->toBe(9)
        ->and(SecurityEvent::query()->where('type', SecurityEventType::MfaRecoveryCodeUsed)->count())->toBe(1);

    $this->post('/signout');
    password_step();
    answer($this->codes[0])->assertSessionHasErrors('code');
    $this->assertGuest('web');
});

it('takes a recovery code with or without the dash, in any case', function () {
    password_step();

    answer(strtoupper(str_replace('-', '', $this->codes[1])))->assertRedirect('/');
});

it('prompts for new codes when two or fewer are left', function () {
    MfaRecoveryCode::query()->whereNull('used_at')->limit(7)->update(['used_at' => now()]);
    password_step();

    answer($this->codes[9])->assertRedirect('/');

    expect(session('toast.message'))->toContain('recovery codes left');
});

it('does not take a recovery code that belongs to someone else', function () {
    $other = User::factory()->create();
    $otherCodes = enroll_two_factor($other);
    password_step();

    answer($otherCodes[0])->assertSessionHasErrors('code');

    $this->assertGuest('web');
    expect(MfaRecoveryCode::query()->where('user_id', $other->id)->whereNull('used_at')->count())->toBe(10);
});

it('does not take a malformed code', function (string $code) {
    password_step();

    answer($code)->assertSessionHasErrors('code');
})->with(['empty-ish' => ['12'], 'letters' => ['abcdef'], 'too long digits' => ['1234567'], 'short recovery' => ['abcde-fghi']]);

it('sends the person on to the page they wanted', function () {
    $this->get('/account/security')->assertRedirect('/signin');
    password_step();

    answer(totp_now())->assertRedirect('/account/security');
});

it('gives a half-signed-in browser the same answer as a guest on every route that needs a person', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
        ->map(fn ($route) => '/'.ltrim(preg_replace('/\{[^}]+\}/', 'x', $route->uri()), '/'))
        ->unique()->values();

    expect($routes->count())->toBeGreaterThan(3);

    $guest = $routes->mapWithKeys(function (string $uri) {
        $response = $this->get($uri);

        return [$uri => [$response->getStatusCode(), $response->headers->get('Location')]];
    });

    password_step();

    foreach ($routes as $uri) {
        $response = $this->get($uri);
        expect([$response->getStatusCode(), $response->headers->get('Location')])->toBe($guest[$uri], "Route {$uri} treats a pending sign-in differently from a guest.");
    }

    $this->getJson('/account/security')->assertUnauthorized();
    $this->postJson('/account/two-factor', ['password' => 'correct horse'])->assertUnauthorized();
    expect(auth('web')->check())->toBeFalse();
});

it('puts no user in the shared props of a pending sign-in', function () {
    password_step();

    $this->get('/signin')->assertInertia(fn ($page) => $page->where('auth.user', null));
});
