<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

const GOOD_PASSWORD = 'correct horse battery';

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('signup-ip|127.0.0.1');
});

function sign_up(array $fields = []): TestResponse
{
    return test()->from('/signup')->post('/signup', [
        'email' => 'new@acme.dev', 'password' => GOOD_PASSWORD, ...$fields,
    ]);
}

function emails_requested(): array
{
    return OutboxMessageRecord::query()->orderBy('id')->get()
        ->map(fn ($m) => [$m->payload['template'], $m->payload['user_id']])->all();
}

it('shows the sign-up screen to a guest and sends a signed-in person away', function () {
    $this->get('/signup')->assertOk()->assertInertia(fn ($page) => $page->component('auth/SignUp'));

    $this->actingAs(User::factory()->create())->get('/signup')->assertRedirect('/');
});

it('creates the account, signs the person in and asks for the confirmation email', function () {
    sign_up(['name' => 'Nia'])->assertRedirect('/signup/check-email');

    $user = User::query()->where('email', 'new@acme.dev')->firstOrFail();

    expect($user->name)->toBe('Nia')
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check(GOOD_PASSWORD, $user->password))->toBeTrue()
        ->and(emails_requested())->toBe([['verify_email', $user->id]]);
    $this->assertAuthenticatedAs($user, 'web');
});

it('names the account after the address when no name is given, and lowercases the address', function () {
    sign_up(['email' => 'Dev.Person@Acme.DEV']);

    expect(User::query()->firstOrFail()->only(['name', 'email']))->toBe(['name' => 'dev.person', 'email' => 'dev.person@acme.dev']);
});

it('creates nothing for an address that has an account, signs nobody in, and tells its owner', function () {
    $existing = User::factory()->create(['email' => 'new@acme.dev', 'password' => 'the original password']);

    sign_up(['password' => 'a different password'])->assertRedirect('/signup/check-email');

    $this->assertGuest('web');
    expect(User::query()->count())->toBe(1)
        ->and(Hash::check('the original password', $existing->fresh()->password))->toBeTrue()
        ->and(emails_requested())->toBe([['signup_attempt_existing', $existing->id]]);
});

it('answers a new and an existing address identically', function () {
    User::factory()->create(['email' => 'taken@acme.dev']);

    $new = sign_up(['email' => 'fresh@acme.dev']);
    $this->post('/signout');
    $existing = sign_up(['email' => 'taken@acme.dev']);

    expect($new->getStatusCode())->toBe($existing->getStatusCode())
        ->and($new->headers->get('Location'))->toBe($existing->headers->get('Location'));
});

it('hashes the password in both cases, so they take about as long', function () {
    User::factory()->create(['email' => 'taken@acme.dev']);
    Hash::spy();

    sign_up(['email' => 'taken@acme.dev']);

    Hash::shouldHaveReceived('make')->once();
});

it('rejects a weak password the same way for any address', function (string $password, string $message) {
    User::factory()->create(['email' => 'taken@acme.dev']);

    foreach (['taken@acme.dev', 'fresh@acme.dev'] as $email) {
        sign_up(['email' => $email, 'password' => $password])->assertSessionHasErrors('password');
        expect(session('errors')->first('password'))->toBe($message);
    }

    expect(User::query()->count())->toBe(1);
})->with([
    'short' => ['short one', 'At least 12 characters'],
    'common' => ['Password1234', 'Not a common password'],
]);

it('rejects the address as a password', function () {
    sign_up(['email' => 'long.address@acme.dev', 'password' => 'long.address@acme.dev'])->assertSessionHasErrors('password');
    expect(session('errors')->first('password'))->toBe('Not your email');
});

it('rejects a malformed address', function () {
    sign_up(['email' => 'not-an-email'])->assertSessionHasErrors('email');
});

it('allows five sign-ups an hour from one network', function () {
    foreach (range(1, 5) as $n) {
        sign_up(['email' => "person{$n}@acme.dev"])->assertRedirect('/signup/check-email');
        $this->post('/signout');
    }

    sign_up(['email' => 'person6@acme.dev'])->assertStatus(429);
});

it('allows three tries an hour for one address', function () {
    foreach (range(1, 3) as $n) {
        sign_up()->assertRedirect('/signup/check-email');
        $this->post('/signout');
    }

    sign_up()->assertStatus(429);
});

it('shows the same check-your-email page for both, with a masked address', function () {
    sign_up();

    $this->get('/signup/check-email')->assertOk()->assertInertia(fn ($page) => $page
        ->component('auth/CheckEmail')->where('maskedEmail', 'ne••@acme.dev')->where('canContinue', true));
});

it('sends someone with nothing to check back to sign-up', function () {
    $this->get('/signup/check-email')->assertRedirect('/signup');
});

it('does not let an existing address continue setup', function () {
    User::factory()->create(['email' => 'new@acme.dev']);
    sign_up();

    $this->get('/signup/check-email')->assertInertia(fn ($page) => $page->where('canContinue', false));
});

it('keeps passwords, addresses and links out of the queued message', function () {
    sign_up();

    $payload = json_encode(OutboxMessageRecord::query()->firstOrFail()->payload);

    expect($payload)->not->toContain(GOOD_PASSWORD)->not->toContain('new@acme.dev')->not->toContain('http');
});

it('answers the common-password check with that and nothing else', function () {
    $this->postJson('/password/check', ['password' => 'Password1234'])->assertOk()->assertExactJson(['common' => true]);
    $this->postJson('/password/check', ['password' => 'correct horse battery'])->assertOk()->assertExactJson(['common' => false]);
    $this->postJson('/password/check', [])->assertOk()->assertExactJson(['common' => false]);
});
