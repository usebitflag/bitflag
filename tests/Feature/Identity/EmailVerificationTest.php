<?php

declare(strict_types=1);

use App\Modules\Identity\Events\EmailVerified;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Queries\EmailVerification;
use App\Shared\Errors\EmailNotVerified;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('signup-ip|127.0.0.1');
    $this->user = User::factory()->unverified()->create(['email' => 'maya@acme.dev']);
});

function link_for(User $user, ?string $hash = null, int $hours = 24): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHours($hours), ['id' => $user->id, 'hash' => $hash ?? sha1($user->email)]);
}

it('confirms the address from the signed link and announces it', function () {
    Event::fake([EmailVerified::class]);

    $this->actingAs($this->user)->get(link_for($this->user))->assertRedirect('/');

    expect($this->user->fresh()->email_verified_at)->not->toBeNull();
    Event::assertDispatched(EmailVerified::class, fn (EmailVerified $e) => $e->userId === $this->user->id);
});

it('says so when the address was already confirmed, and changes nothing', function () {
    $verified = User::factory()->create();
    $before = $verified->email_verified_at;
    Event::fake([EmailVerified::class]);

    $this->actingAs($verified)->get(link_for($verified))->assertRedirect('/')->assertSessionHas('toast.message', 'Your email is already confirmed.');

    Event::assertNotDispatched(EmailVerified::class);
    expect($verified->fresh()->email_verified_at->equalTo($before))->toBeTrue();
});

it('refuses an unsigned, tampered or expired link', function () {
    $this->actingAs($this->user)->get("/email/verify/{$this->user->id}/".sha1('maya@acme.dev'))->assertForbidden();
    $this->actingAs($this->user)->get(str_replace('maya', 'mara', link_for($this->user)).'x')->assertForbidden();
    $this->actingAs($this->user)->get(link_for($this->user, hours: -1))->assertForbidden();

    expect($this->user->fresh()->email_verified_at)->toBeNull();
});

it('refuses a link for another account or for an address that has since changed', function () {
    $other = User::factory()->unverified()->create();

    $this->actingAs($other)->get(link_for($this->user))->assertForbidden();
    $this->actingAs($this->user)->get(link_for($this->user, sha1('old@acme.dev')))->assertForbidden();

    expect($this->user->fresh()->email_verified_at)->toBeNull();
});

it('asks a signed-out person to sign in and then brings them back to the link', function () {
    $link = link_for($this->user);

    $this->get($link)->assertRedirect('/signin');
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'password'])->assertRedirect($link);
});

it('sends the confirmation email again at most once a minute', function () {
    $this->actingAs($this->user)->post('/email/verification-notification')->assertRedirect();
    $this->actingAs($this->user)->post('/email/verification-notification')->assertStatus(429);

    expect(OutboxMessageRecord::query()->where('payload->template', 'verify_email')->count())->toBe(1);
});

it('sends nothing to a confirmed account', function () {
    $this->actingAs(User::factory()->create())->post('/email/verification-notification')->assertRedirect();

    expect(OutboxMessageRecord::query()->count())->toBe(0);
});

it('moves an unconfirmed account to a corrected address and sends the confirmation there', function () {
    $this->actingAs($this->user)->post('/signup/change-email', ['email' => 'Maya.Chen@Acme.dev'])->assertRedirect('/signup/check-email');

    expect($this->user->fresh()->email)->toBe('maya.chen@acme.dev')
        ->and(OutboxMessageRecord::query()->where('payload->template', 'verify_email')->count())->toBe(1);
});

it('leaves the account alone and tells the owner when the new address is taken', function () {
    $owner = User::factory()->create(['email' => 'taken@acme.dev']);

    $taken = $this->actingAs($this->user)->post('/signup/change-email', ['email' => 'taken@acme.dev']);
    $free = $this->actingAs($this->user)->post('/signup/change-email', ['email' => 'free@acme.dev']);

    expect($taken->getStatusCode())->toBe($free->getStatusCode())
        ->and($taken->headers->get('Location'))->toBe($free->headers->get('Location'))
        ->and(OutboxMessageRecord::query()->where('payload->template', 'signup_attempt_existing')->where('user_id', $owner->id)->count())->toBe(1);
});

it('does not let a confirmed account change its address this way', function () {
    $this->actingAs(User::factory()->create())->post('/signup/change-email', ['email' => 'x@acme.dev'])->assertForbidden();
});

it('shows the banner flag to an unconfirmed person only', function () {
    $this->actingAs($this->user)->get('/no-access')->assertInertia(fn (Assert $page) => $page->where('auth.user.emailVerified', false));
    $this->actingAs(User::factory()->create())->get('/no-access')->assertInertia(fn (Assert $page) => $page->where('auth.user.emailVerified', true));
});

it('gives actions a gate that throws EMAIL_NOT_VERIFIED', function () {
    $gate = app(EmailVerification::class);

    expect($gate->isVerified($this->user->id))->toBeFalse()
        ->and(fn () => $gate->assertVerified($this->user->id))->toThrow(EmailNotVerified::class);
    $gate->assertVerified(User::factory()->create()->id);

    $exception = new EmailNotVerified;
    expect($exception->errorCode())->toBe('EMAIL_NOT_VERIFIED')->and($exception->httpStatus())->toBe(403);
});
