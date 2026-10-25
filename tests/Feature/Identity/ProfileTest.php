<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\PruneSecurityState;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->user = User::factory()->create(['name' => 'Maya Santos', 'email' => 'maya@acme.dev', 'password' => 'correct horse battery', 'timezone' => 'UTC']);
});

function change_link(User $user, ?string $address = null, int $hours = 24): string
{
    return URL::temporarySignedRoute('email.change.confirm', now()->addHours($hours), ['id' => $user->id, 'hash' => sha1($address ?? (string) $user->pending_email)]);
}

describe('the profile page', function () {
    it('shows the name, email, time zone and any change waiting', function () {
        $this->user->forceFill(['pending_email' => 'new@acme.dev'])->save();

        $this->actingAs($this->user)->get('/account/profile')->assertInertia(fn (Assert $page) => $page
            ->component('account/Profile')->where('profile.name', 'Maya Santos')->where('profile.email', 'maya@acme.dev')
            ->where('profile.timezone', 'UTC')->where('profile.pendingEmail', 'new@acme.dev')->where('profile.emailVerified', true)
            ->where('timezones', fn ($zones) => in_array('Europe/Berlin', $zones->toArray(), true)));
    });

    it('is for signed-in people', function () {
        $this->get('/account/profile')->assertRedirect('/signin');
        $this->put('/account/profile', ['name' => 'x', 'timezone' => 'UTC'])->assertRedirect('/signin');
    });

    it('saves the name and time zone', function () {
        $this->actingAs($this->user)->put('/account/profile', ['name' => '  Maya S.  ', 'timezone' => 'Europe/Berlin'])->assertRedirect()->assertSessionHas('toast.message', 'Profile saved.');

        $user = $this->user->fresh();
        expect($user->name)->toBe('Maya S.')->and($user->timezone)->toBe('Europe/Berlin');
    });

    it('refuses what cannot be used', function (array $change, string $field) {
        $this->actingAs($this->user)->from('/account/profile')->put('/account/profile', ['name' => 'Maya', 'timezone' => 'UTC', ...$change])->assertSessionHasErrors($field);

        expect($this->user->fresh()->name)->toBe('Maya Santos');
    })->with([
        'no name' => [['name' => '  '], 'name'],
        'long name' => [['name' => str_repeat('a', 121)], 'name'],
        'unknown zone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'wrong case' => [['timezone' => 'europe/berlin'], 'timezone'],
    ]);
});

describe('changing the email address', function () {
    function request_change(string $email = 'new@acme.dev', string $password = 'correct horse battery'): TestResponse
    {
        return test()->actingAs(test()->user)->from('/account/profile')->post('/account/email', ['email' => $email, 'password' => $password]);
    }

    it('keeps the old address, remembers the new one and asks it to confirm', function () {
        request_change('New@Acme.dev')->assertRedirect()->assertSessionHas('toast');

        $user = $this->user->fresh();

        expect($user->email)->toBe('maya@acme.dev')->and($user->pending_email)->toBe('new@acme.dev')
            ->and(OutboxMessageRecord::query()->where('payload->template', 'confirm_new_email')->count())->toBe(1);
    });

    it('needs the password', function () {
        request_change('new@acme.dev', 'wrong')->assertSessionHasErrors('password');

        expect($this->user->fresh()->pending_email)->toBeNull();
    });

    it('refuses an invalid address and the one already in use by this account', function () {
        request_change('nope')->assertSessionHasErrors('email');
        request_change('maya@acme.dev')->assertSessionHasErrors('email');
    });

    it('answers the same for an address that belongs to someone else, changes nothing and tells that person', function () {
        $other = User::factory()->create(['email' => 'taken@acme.dev']);

        $taken = request_change('taken@acme.dev');
        $free = request_change('free@acme.dev');

        expect($taken->getStatusCode())->toBe($free->getStatusCode())
            ->and(session('toast.message'))->toContain('We sent a link')
            ->and(OutboxMessageRecord::query()->where('payload->template', 'signup_attempt_existing')->where('payload->user_id', $other->id)->count())->toBe(1)
            ->and($this->user->fresh()->pending_email)->toBe('free@acme.dev');
    });

    it('also treats an address waiting on another account as taken', function () {
        User::factory()->create(['email' => 'other@acme.dev', 'pending_email' => 'waiting@acme.dev']);

        request_change('waiting@acme.dev');

        expect($this->user->fresh()->pending_email)->toBeNull();
    });

    it('limits how often it can be asked', function () {
        foreach (range(1, 5) as $n) {
            request_change("n{$n}@acme.dev")->assertRedirect();
        }

        request_change('n6@acme.dev')->assertStatus(429);
    });
});

describe('confirming the new address', function () {
    beforeEach(function () {
        $this->user->forceFill(['pending_email' => 'new@acme.dev', 'email_verified_at' => null])->save();
    });

    it('makes it the account\'s address, confirmed, and tells the old one', function () {
        $this->actingAs($this->user)->get(change_link($this->user))->assertRedirect('/account/profile')->assertSessionHas('toast.message', 'Your email address was changed.');

        $user = $this->user->fresh();

        expect($user->email)->toBe('new@acme.dev')
            ->and($user->pending_email)->toBeNull()
            ->and($user->previous_email)->toBe('maya@acme.dev')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->email_changed_at)->not->toBeNull()
            ->and(SecurityEvent::query()->where('type', SecurityEventType::EmailChanged)->count())->toBe(1)
            ->and(OutboxMessageRecord::query()->where('payload->template', 'email_changed')->firstOrFail()->payload['details'])->toBe(['new_email' => 'new@acme.dev']);
    });

    it('lets the person sign in with the new address and not the old', function () {
        $this->actingAs($this->user)->get(change_link($this->user));
        auth('web')->logout();

        $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse battery'])->assertSessionHasErrors('email');
        $this->post('/signin', ['email' => 'new@acme.dev', 'password' => 'correct horse battery'])->assertRedirect();
    });

    it('works once', function () {
        $link = change_link($this->user);
        $this->actingAs($this->user)->get($link);

        $this->actingAs($this->user)->get($link)->assertRedirect('/account/profile')->assertSessionHas('toast.message', 'This link has expired or was already used.');
        expect($this->user->fresh()->previous_email)->toBe('maya@acme.dev');
    });

    it('refuses an unsigned, expired or tampered link, and another person\'s', function () {
        $this->actingAs($this->user)->get("/account/email/confirm/{$this->user->id}/".sha1('new@acme.dev'))->assertForbidden();
        $this->actingAs($this->user)->get(change_link($this->user, hours: -1))->assertForbidden();
        $other = User::factory()->create();
        $this->actingAs($other)->get(change_link($this->user))->assertForbidden();

        expect($this->user->fresh()->email)->toBe('maya@acme.dev');
    });

    it('does nothing for a link to an address that is no longer the one waiting', function () {
        $link = change_link($this->user, 'old-request@acme.dev');

        $this->actingAs($this->user)->get($link)->assertRedirect('/account/profile')->assertSessionHas('toast.message', 'This link has expired or was already used.');

        expect($this->user->fresh()->email)->toBe('maya@acme.dev');
    });

    it('says so, and drops the request, when someone took the address meanwhile', function () {
        $link = change_link($this->user);
        User::factory()->create(['email' => 'new@acme.dev']);

        $this->actingAs($this->user)->get($link)->assertRedirect('/account/profile')->assertSessionHasErrors('email');

        expect($this->user->fresh()->pending_email)->toBeNull()->and($this->user->fresh()->email)->toBe('maya@acme.dev');
    });

    it('forgets the old address after 30 days', function () {
        $this->actingAs($this->user)->get(change_link($this->user));
        $this->travel(29)->days();
        app(PruneSecurityState::class)->handle();
        expect($this->user->fresh()->previous_email)->toBe('maya@acme.dev');

        $this->travel(2)->days();
        $result = app(PruneSecurityState::class)->handle();

        expect($result['previous_emails'])->toBe(1)->and($this->user->fresh()->previous_email)->toBeNull()->and($this->user->fresh()->email)->toBe('new@acme.dev');
    });
});
