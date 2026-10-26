<?php

declare(strict_types=1);

use App\Modules\Identity\Data\SocialProvider;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    config([
        'services.google.client_id' => 'google-id',
        'services.google.client_secret' => 'google-secret',
        'services.github.client_id' => 'github-id',
        'services.github.client_secret' => 'github-secret',
    ]);
});

/** Starts the trip to the provider and returns the state it was sent with. */
function trip(string $provider = 'google'): string
{
    $response = test()->get("/auth/{$provider}/redirect")->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

function googleSays(array $profile): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response($profile),
    ]);
}

function githubSays(array $user, array $emails): void
{
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'token']),
        'api.github.com/user/emails' => Http::response($emails),
        'api.github.com/user' => Http::response($user),
    ]);
}

function comeBack(string $state, string $provider = 'google', array $query = []): TestResponse
{
    return test()->get("/auth/{$provider}/callback?".http_build_query(['state' => $state, 'code' => 'the-code', ...$query]));
}

describe('the trip to the provider', function () {
    it('goes to Google with a state and a PKCE challenge', function () {
        $location = (string) test()->get('/auth/google/redirect')->assertRedirect()->headers->get('Location');

        expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        expect($query['client_id'])->toBe('google-id')
            ->and($query['redirect_uri'])->toBe(url('/auth/google/callback'))
            ->and($query['code_challenge_method'])->toBe('S256')
            ->and(strlen((string) $query['state']))->toBe(40)
            ->and($query['scope'])->toBe('openid email profile');
    });

    it('goes to GitHub asking for the email scope', function () {
        $location = (string) test()->get('/auth/github/redirect')->assertRedirect()->headers->get('Location');

        expect($location)->toStartWith('https://github.com/login/oauth/authorize?')->and($location)->toContain('user%3Aemail');
    });

    it('does not go anywhere for a provider that is not set up, or does not exist', function () {
        config(['services.github.client_secret' => null]);

        test()->get('/auth/github/redirect')->assertRedirect('/signin')->assertSessionHasErrors('email');
        test()->get('/auth/facebook/redirect')->assertNotFound();
    });

    it('offers only the providers that are set up', function () {
        config(['services.github.client_id' => null]);

        test()->get('/signin')->assertInertia(fn ($page) => $page->where('providers', [['key' => 'google', 'label' => 'Google']]));
        test()->get('/signup')->assertInertia(fn ($page) => $page->where('providers', [['key' => 'google', 'label' => 'Google']]));
    });
});

describe('signing up', function () {
    it('creates a confirmed account without a password, links it and signs the person in', function () {
        googleSays(['sub' => '1001', 'email' => 'Nia@Startup.dev', 'email_verified' => true, 'name' => 'Nia Reyes']);

        comeBack(trip())->assertRedirect('/');

        $user = User::query()->where('email', 'nia@startup.dev')->firstOrFail();
        expect($user->name)->toBe('Nia Reyes')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->password)->toBeNull()
            ->and($user->hasPassword())->toBeFalse()
            ->and(SocialIdentity::query()->where('user_id', $user->id)->where('provider', 'google')->where('provider_user_id', '1001')->count())->toBe(1);
        test()->assertAuthenticatedAs($user, 'web');
        expect(SecurityEvent::query()->where('user_id', $user->id)->pluck('type')->map(fn ($type) => $type->value)->all())
            ->toContain(SecurityEventType::SignedUpWithProvider->value, SecurityEventType::SignInSucceeded->value);
    });

    it('uses the part of the email before the @ when the provider gives no name', function () {
        googleSays(['sub' => '1002', 'email' => 'sam@startup.dev', 'email_verified' => true]);

        comeBack(trip());

        expect(User::query()->where('email', 'sam@startup.dev')->firstOrFail()->name)->toBe('sam');
    });

    it('uses only the primary verified email from GitHub', function () {
        githubSays(
            ['id' => 77, 'login' => 'nia-dev', 'name' => null],
            [
                ['email' => 'other@startup.dev', 'primary' => false, 'verified' => true],
                ['email' => 'Nia@Startup.dev', 'primary' => true, 'verified' => true],
            ],
        );

        comeBack(trip('github'), 'github')->assertRedirect('/');

        $user = User::query()->where('email', 'nia@startup.dev')->firstOrFail();
        expect($user->name)->toBe('nia-dev')
            ->and(SocialIdentity::query()->where('user_id', $user->id)->firstOrFail()->provider)->toBe(SocialProvider::GitHub);
    });

    it('refuses an email the provider has not verified', function () {
        googleSays(['sub' => '1003', 'email' => 'nia@startup.dev', 'email_verified' => false]);

        comeBack(trip())->assertRedirect('/signin')->assertSessionHasErrors('email');

        expect(User::query()->where('email', 'nia@startup.dev')->exists())->toBeFalse();
        test()->assertGuest('web');
    });

    it('refuses a GitHub account whose primary email is not verified', function () {
        githubSays(['id' => 78, 'login' => 'sam'], [['email' => 'sam@startup.dev', 'primary' => true, 'verified' => false]]);

        comeBack(trip('github'), 'github')->assertRedirect('/signin')->assertSessionHasErrors('email');

        expect(User::query()->count())->toBe(0);
    });
});

describe('signing in', function () {
    beforeEach(function () {
        $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => null]);
        SocialIdentity::query()->create([
            'user_id' => $this->user->id,
            'provider' => SocialProvider::Google,
            'provider_user_id' => '555',
            'email' => 'maya@acme.dev',
            'created_at' => now(),
        ]);
    });

    it('signs in a linked account, even when the provider now shows another email', function () {
        googleSays(['sub' => '555', 'email' => 'maya@elsewhere.dev', 'email_verified' => true, 'name' => 'Maya']);

        comeBack(trip())->assertRedirect('/');

        test()->assertAuthenticatedAs($this->user, 'web');
        expect(User::query()->count())->toBe(1)->and($this->user->fresh()->email)->toBe('maya@acme.dev')
            ->and(SocialIdentity::query()->firstOrFail()->last_used_at)->not->toBeNull();
    });

    it('signs in a linked account whose provider email is unverified, because the link is the provider id', function () {
        googleSays(['sub' => '555', 'email' => 'maya@acme.dev', 'email_verified' => false]);

        comeBack(trip())->assertRedirect('/');

        test()->assertAuthenticatedAs($this->user, 'web');
    });

    it('still asks for the second factor', function () {
        enroll_two_factor($this->user);
        googleSays(['sub' => '555', 'email' => 'maya@acme.dev', 'email_verified' => true]);

        comeBack(trip())->assertRedirect('/two-factor-challenge');

        test()->assertGuest('web');
        test()->get('/two-factor-challenge')->assertOk();
        test()->post('/two-factor-challenge', ['code' => totp_now()])->assertRedirect();
        test()->assertAuthenticatedAs($this->user, 'web');
    });

    it('refuses a deactivated account', function () {
        $this->user->forceFill(['status' => AccountStatus::Deactivated])->save();
        googleSays(['sub' => '555', 'email' => 'maya@acme.dev', 'email_verified' => true]);

        comeBack(trip())->assertRedirect('/signin')->assertSessionHasErrors('email');

        test()->assertGuest('web');
    });
});

describe('an email that already has an account', function () {
    it('links nothing and signs no one in, and says what to do', function () {
        $existing = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
        googleSays(['sub' => '999', 'email' => 'maya@acme.dev', 'email_verified' => true]);

        $response = comeBack(trip())->assertRedirect('/signin');

        $response->assertSessionHasErrors(['email' => 'An account with ma••@acme.dev already exists. Sign in with your email and password.']);
        test()->assertGuest('web');
        expect(SocialIdentity::query()->count())->toBe(0)->and($existing->fresh()->password)->not->toBeNull();
    });
});

describe('the return trip', function () {
    it('refuses a state that is not the one it sent', function () {
        googleSays(['sub' => '1', 'email' => 'nia@startup.dev', 'email_verified' => true]);
        trip();

        comeBack(str_repeat('a', 40))->assertRedirect('/signin')->assertSessionHasErrors('email');

        expect(User::query()->count())->toBe(0);
    });

    it('refuses a return trip with no trip before it', function () {
        googleSays(['sub' => '1', 'email' => 'nia@startup.dev', 'email_verified' => true]);

        comeBack(str_repeat('a', 40))->assertRedirect('/signin')->assertSessionHasErrors('email');
    });

    it('works only once', function () {
        googleSays(['sub' => '1', 'email' => 'nia@startup.dev', 'email_verified' => true]);
        $state = trip();

        comeBack($state)->assertRedirect('/');
        test()->post('/signout');
        comeBack($state)->assertRedirect('/signin')->assertSessionHasErrors('email');
    });

    it('refuses a state started for another provider', function () {
        googleSays(['sub' => '1', 'email' => 'nia@startup.dev', 'email_verified' => true]);

        comeBack(trip('github'), 'google')->assertRedirect('/signin')->assertSessionHasErrors('email');
    });

    it('says so when the person cancels at the provider', function () {
        $state = trip();

        test()->get('/auth/google/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']))
            ->assertRedirect('/signin')->assertSessionHasErrors(['email' => 'Google sign-in was cancelled.']);
    });

    it('says so when the provider refuses the code', function () {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        comeBack(trip())->assertRedirect('/signin')->assertSessionHasErrors('email');
        expect(User::query()->count())->toBe(0);
    });

    it('says so when the provider cannot be reached', function () {
        Http::fake(['oauth2.googleapis.com/token' => fn () => throw new ConnectionException('down')]);

        comeBack(trip())->assertRedirect('/signin')->assertSessionHasErrors(['email' => 'Google could not be reached. Try again.']);
    });

    it('is closed to a person who is already signed in', function () {
        test()->actingAs(User::factory()->create(), 'web')->get('/auth/google/redirect')->assertRedirect();
        test()->assertAuthenticated('web');
        expect(User::query()->count())->toBe(1);
    });
});

describe('an account with no password', function () {
    it('cannot sign in with any password, and never matches the current password', function () {
        $user = User::factory()->create(['email' => 'nia@startup.dev', 'password' => null]);

        test()->post('/signin', ['email' => 'nia@startup.dev', 'password' => 'anything at all'])->assertSessionHasErrors('email');

        expect($user->passwordMatches(''))->toBeFalse()->and($user->passwordMatches('anything at all'))->toBeFalse();
        test()->assertGuest('web');
    });

    it('cannot delete the account with a made-up password', function () {
        $user = User::factory()->create(['email' => 'nia@startup.dev', 'password' => null]);

        test()->actingAs($user, 'web')->delete('/account', ['password' => 'anything at all'])->assertSessionHasErrors();

        expect(User::query()->find($user->id))->not->toBeNull();
    });
});

describe('setting a password after signing up with a provider', function () {
    it('tells the page the person has no password, and emails the usual reset link', function () {
        $user = User::factory()->create(['email' => 'nia@startup.dev', 'password' => null]);

        test()->actingAs($user, 'web')->get('/account/security')->assertInertia(fn ($page) => $page->where('auth.user.hasPassword', false));

        test()->actingAs($user, 'web')->post('/account/password/link')->assertRedirect();

        expect(OutboxMessageRecord::query()->where('type', 'internal.email.requested')->get()->contains(fn ($m) => ($m->payload['template'] ?? null) === 'password_reset' && ($m->payload['user_id'] ?? null) === $user->id))->toBeTrue();
    });

    it('lets them set one with that link, after which the password works', function () {
        $user = User::factory()->create(['email' => 'nia@startup.dev', 'password' => null]);
        $token = Password::broker()->createToken($user);

        test()->post('/reset-password', ['token' => $token, 'user' => PublicId::encode(IdPrefix::User, $user->id), 'password' => 'a long passphrase here'])->assertRedirect();
        test()->post('/signout');

        expect($user->fresh()->hasPassword())->toBeTrue();
        test()->post('/signin', ['email' => 'nia@startup.dev', 'password' => 'a long passphrase here'])->assertRedirect();
        test()->assertAuthenticatedAs($user, 'web');
    });

    it('says a person with a password has one', function () {
        $user = User::factory()->create(['password' => 'correct horse']);

        test()->actingAs($user, 'web')->get('/account/security')->assertInertia(fn ($page) => $page->where('auth.user.hasPassword', true));
    });
});
