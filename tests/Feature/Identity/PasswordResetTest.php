<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('reset-ip|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'the old password']);
});

function reset_token(User $user): string
{
    return Password::broker()->createToken($user);
}

function reset_with(string $token, User $user, string $password = 'a brand new password'): TestResponse
{
    return test()->from('/reset-password/'.$token)->post('/reset-password', [
        'token' => $token, 'user' => PublicId::encode(IdPrefix::User, $user->id), 'password' => $password,
    ]);
}

function reset_emails(): int
{
    return OutboxMessageRecord::query()->where('payload->template', 'password_reset')->count();
}

it('answers a request the same for an account and for an unknown address', function () {
    $known = $this->post('/forgot-password', ['email' => 'maya@acme.dev']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@acme.dev']);

    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->headers->get('Location'))->toEndWith('/forgot-password/sent')
        ->and($unknown->headers->get('Location'))->toBe($known->headers->get('Location'))
        ->and(reset_emails())->toBe(1);
});

it('sends nothing to a deactivated account, and answers the same', function () {
    $this->user->forceFill(['status' => 'deactivated'])->save();

    $this->post('/forgot-password', ['email' => 'maya@acme.dev'])->assertRedirect('/forgot-password/sent');

    expect(reset_emails())->toBe(0);
});

it('shows the same sent page, naming the address typed', function () {
    $this->post('/forgot-password', ['email' => 'Nobody@Acme.dev']);

    $this->get('/forgot-password/sent')->assertInertia(fn ($page) => $page
        ->component('auth/ResetLinkSent')->where('email', 'nobody@acme.dev')->where('resendSeconds', 60));
});

it('sends someone with no request back to the form', function () {
    $this->get('/forgot-password/sent')->assertRedirect('/forgot-password');
    $this->post('/forgot-password/resend')->assertRedirect('/forgot-password');
});

it('allows three emails an hour for one address, counting a resend', function () {
    $this->post('/forgot-password', ['email' => 'maya@acme.dev']);
    $this->post('/forgot-password/resend');
    $this->post('/forgot-password/resend');

    $this->post('/forgot-password/resend')->assertStatus(429);
    expect(reset_emails())->toBe(3);
});

it('allows ten requests an hour from one network', function () {
    foreach (range(1, 10) as $n) {
        $this->post('/forgot-password', ['email' => "p{$n}@acme.dev"])->assertRedirect('/forgot-password/sent');
    }

    $this->post('/forgot-password', ['email' => 'p11@acme.dev'])->assertStatus(429);
});

it('shows the reset form for a usable link and a notice for a spent one', function () {
    $token = reset_token($this->user);
    $user = PublicId::encode(IdPrefix::User, $this->user->id);

    $this->get("/reset-password/{$token}?user={$user}")->assertInertia(fn ($page) => $page->component('auth/ResetPassword')->where('usable', true));
    $this->get("/reset-password/wrong-token?user={$user}")->assertInertia(fn ($page) => $page->where('usable', false));
    $this->get("/reset-password/{$token}")->assertInertia(fn ($page) => $page->where('usable', false));
    $this->get("/reset-password/{$token}?user=garbage")->assertInertia(fn ($page) => $page->where('usable', false));
});

it('sets the password, signs the person in on this browser and mails them', function () {
    $token = reset_token($this->user);

    reset_with($token, $this->user)->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
    expect(Hash::check('a brand new password', $this->user->fresh()->password))->toBeTrue()
        ->and(OutboxMessageRecord::query()->where('payload->template', 'password_changed')->count())->toBe(1)
        ->and(SecurityEvent::query()->where('type', SecurityEventType::PasswordReset)->count())->toBe(1);
});

it('works once', function () {
    $token = reset_token($this->user);
    reset_with($token, $this->user);
    $this->post('/signout');

    reset_with($token, $this->user, 'another new password')->assertSessionHasErrors('token');

    $this->assertGuest('web');
    expect(Hash::check('a brand new password', $this->user->fresh()->password))->toBeTrue();
});

it('stops working after 60 minutes', function () {
    $token = reset_token($this->user);
    $this->travel(61)->minutes();

    reset_with($token, $this->user)->assertSessionHasErrors('token');

    expect(Hash::check('the old password', $this->user->fresh()->password))->toBeTrue();
});

it('refuses a token that belongs to another account', function () {
    $other = User::factory()->create();

    reset_with(reset_token($other), $this->user)->assertSessionHasErrors('token');
});

it('applies the password rules', function (string $password, string $message) {
    reset_with(reset_token($this->user), $this->user, $password)->assertSessionHasErrors('password');

    expect(session('errors')->get('password'))->toContain($message)
        ->and(Hash::check('the old password', $this->user->fresh()->password))->toBeTrue();
})->with([
    'short' => ['short one', 'At least 12 characters'],
    'common' => ['Password1234', 'Not a common password'],
    'the email' => ['maya@acme.dev', 'Not your email'],
]);

it('signs the person out everywhere and revokes trusted browsers', function () {
    DB::table('sessions')->insert([
        ['id' => 'other-1', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'other-2', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'someone-else', 'user_id' => User::factory()->create()->id, 'payload' => '', 'last_activity' => time()],
    ]);
    DB::table('trusted_browsers')->insert([
        'id' => strtolower((string) Str::ulid()), 'user_id' => $this->user->id, 'token_hash' => random_bytes(32),
        'device_label' => 'Chrome on macOS', 'created_at' => now(), 'expires_at' => now()->addDays(30),
    ]);

    reset_with(reset_token($this->user), $this->user);

    expect(DB::table('sessions')->whereIn('id', ['other-1', 'other-2'])->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'someone-else')->count())->toBe(1)
        ->and(DB::table('trusted_browsers')->whereNull('revoked_at')->count())->toBe(0);
});

it('clears a sign-in lock', function () {
    foreach (range(1, 5) as $n) {
        $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'wrong']);
    }
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'the old password'])->assertSessionHasErrors('email');

    reset_with(reset_token($this->user), $this->user);
    $this->post('/signout');

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'a brand new password'])->assertRedirect('/');
});

it('never skips the second factor', function () {
    MfaFactor::query()->create(['user_id' => $this->user->id, 'type' => MfaFactorType::Totp, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);

    reset_with(reset_token($this->user), $this->user)->assertRedirect('/two-factor-challenge');

    $this->assertGuest('web');
    expect(session('login.pending.user_id'))->toBe($this->user->id)
        ->and(Hash::check('a brand new password', $this->user->fresh()->password))->toBeTrue();
});

it('sets the password of a deactivated account but does not sign it in', function () {
    $token = reset_token($this->user);
    $this->user->forceFill(['status' => 'deactivated'])->save();

    reset_with($token, $this->user)->assertRedirect('/signin');

    $this->assertGuest('web');
});

it('keeps tokens, links and the password out of the queued messages and the security log', function () {
    $this->post('/forgot-password', ['email' => 'maya@acme.dev']);
    reset_with(reset_token($this->user), $this->user);

    $stored = json_encode([OutboxMessageRecord::query()->get()->pluck('payload'), SecurityEvent::query()->get()->toArray()], JSON_INVALID_UTF8_SUBSTITUTE);

    expect($stored)->not->toContain('a brand new password')->not->toContain('maya@acme.dev')->not->toContain('http');
});
