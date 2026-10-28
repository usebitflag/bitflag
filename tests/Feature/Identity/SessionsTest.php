<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\TrustedBrowser;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Queries\ActiveSessions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->stranger = User::factory()->create();
});

function add_session(User $user, string $id, array $extra = []): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        'created_at' => time(), 'device_label' => 'Chrome on macOS', 'location_estimate' => null, ...$extra,
    ]);
}

function add_trusted_browser(User $user, array $extra = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('trusted_browsers')->insert([
        'id' => $id, 'user_id' => $user->id, 'token_hash' => random_bytes(32), 'device_label' => 'Firefox on Linux',
        'created_at' => now(), 'expires_at' => now()->addDays(30), ...$extra,
    ]);

    return $id;
}

/**
 * A request that carries the given session id, so the server knows which session is "this one".
 */
function as_session(string $id): TestCase
{
    return test()->actingAs(test()->user)->withCookie(config('session.cookie'), $id);
}

it('lists a person\'s sessions with a name that is not the session id', function () {
    add_session($this->user, 'secret-session-id-1', ['device_label' => 'Safari on iOS', 'location_estimate' => 'Berlin, Germany']);
    add_session($this->stranger, 'secret-session-id-2');

    $this->actingAs($this->user)->get('/account/security')->assertInertia(function (Assert $page) {
        $page->has('sessions', 1)
            ->where('sessions.0.device', 'Safari on iOS')
            ->where('sessions.0.location', 'Berlin, Germany')
            ->where('sessions.0.ref', ActiveSessions::ref('secret-session-id-1'))
            ->where('sessions.0.current', false);
    });
});

it('marks the session making the request as this device', function () {
    add_session($this->user, str_repeat('a', 40));
    add_session($this->user, str_repeat('b', 40));

    as_session(str_repeat('a', 40))->get('/account/security')->assertInertia(fn (Assert $page) => $page
        ->where('sessions', fn ($sessions) => collect($sessions)->where('current', true)->count() === 1
            && collect($sessions)->firstWhere('current', true)['ref'] === ActiveSessions::ref(str_repeat('a', 40))));
});

it('shows an unknown device and location plainly', function () {
    add_session($this->user, 'a', ['device_label' => null]);

    $this->actingAs($this->user)->get('/account/security')->assertInertia(fn (Assert $page) => $page
        ->where('sessions.0.device', 'Unknown device')->where('sessions.0.location', 'Unknown location'));
});

it('never puts a session id in the page', function () {
    add_session($this->user, 'secret-session-id-1');

    $body = $this->actingAs($this->user)->get('/account/security')->getContent();

    expect($body)->not->toContain('secret-session-id-1');
});

it('signs out one other session of the person', function () {
    add_session($this->user, 'other-one');
    add_session($this->user, 'other-two');

    $this->actingAs($this->user)->delete('/account/sessions/'.ActiveSessions::ref('other-one'))->assertRedirect();

    expect(DB::table('sessions')->where('id', 'other-one')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'other-two')->exists())->toBeTrue()
        ->and(SecurityEvent::query()->where('type', SecurityEventType::SessionRevoked)->count())->toBe(1);
});

it('cannot sign out someone else\'s session, or one that does not exist', function () {
    add_session($this->stranger, 'theirs');

    $this->actingAs($this->user)->delete('/account/sessions/'.ActiveSessions::ref('theirs'))->assertNotFound();
    $this->actingAs($this->user)->delete('/account/sessions/'.str_repeat('a', 32))->assertNotFound();
    $this->actingAs($this->user)->delete('/account/sessions/not-a-ref')->assertNotFound();

    expect(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue();
});

it('does not end the current session through the list', function () {
    $current = str_repeat('c', 40);
    add_session($this->user, $current);

    as_session($current)->delete('/account/sessions/'.ActiveSessions::ref($current))->assertNotFound();

    expect(DB::table('sessions')->where('id', $current)->exists())->toBeTrue();
});

it('signs out every other session and stops trusted browsers, keeping this one', function () {
    $current = str_repeat('c', 40);
    add_session($this->user, $current);
    add_session($this->user, 'other-one');
    add_session($this->user, 'other-two');
    add_session($this->stranger, 'theirs');
    add_trusted_browser($this->user);

    as_session($current)->delete('/account/sessions')->assertRedirect();

    expect(DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all())->toBe([$current])
        ->and(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue()
        ->and(TrustedBrowser::query()->whereNull('revoked_at')->count())->toBe(0);
});

it('lists trusted browsers that are in force and lets the person remove one', function () {
    $keep = add_trusted_browser($this->user);
    $remove = add_trusted_browser($this->user, ['device_label' => 'Edge on Windows']);
    add_trusted_browser($this->user, ['revoked_at' => now()]);
    add_trusted_browser($this->user, ['expires_at' => now()->subDay()]);
    add_trusted_browser($this->stranger);

    $this->actingAs($this->user)->get('/account/security')->assertInertia(fn (Assert $page) => $page->has('trustedBrowsers', 2));

    $this->actingAs($this->user)->delete("/account/trusted-browsers/{$remove}")->assertRedirect();

    expect(TrustedBrowser::query()->whereNull('revoked_at')->where('expires_at', '>', now())->where('user_id', $this->user->id)->pluck('id')->all())->toBe([$keep]);
});

it('cannot remove someone else\'s trusted browser', function () {
    $theirs = add_trusted_browser($this->stranger);

    $this->actingAs($this->user)->delete("/account/trusted-browsers/{$theirs}")->assertNotFound();

    expect(TrustedBrowser::query()->findOrFail($theirs)->revoked_at)->toBeNull();
});

it('is for signed-in people only', function () {
    $this->delete('/account/sessions')->assertRedirect('/signin');
    $this->delete('/account/trusted-browsers/'.str_repeat('a', 26))->assertRedirect('/signin');
});

it('answers the re-sign-in dialog with JSON for a password and a second factor', function () {
    $user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    $this->postJson('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertOk()->assertJson(['two_factor' => false]);
    $this->postJson('/signout');

    enroll_two_factor($user);

    $this->postJson('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertOk()->assertExactJson(['two_factor' => true]);
    $this->postJson('/two-factor-challenge', ['code' => '000000'])->assertStatus(422)->assertJsonPath('error.details.fields.0.path', 'code');
    $this->postJson('/two-factor-challenge', ['code' => totp_now()])->assertOk()->assertExactJson(['ok' => true]);
    $this->assertAuthenticatedAs($user, 'web');
});

it('answers a wrong password in the dialog with the one generic message', function () {
    User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);

    $this->postJson('/signin', ['email' => 'maya@acme.dev', 'password' => 'wrong'])->assertStatus(422)
        ->assertJsonPath('error.details.fields.0.message', "That email and password don't match. Check them and try again.");
});
