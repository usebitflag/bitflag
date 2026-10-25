<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\SessionPolicyLookup;
use App\Modules\Identity\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
});

function browse(string $uri = '/account/security'): TestResponse
{
    return test()->actingAs(test()->user)->get($uri);
}

function with_organization_limit(string $slug, ?int $minutes): void
{
    app()->bind(SessionPolicyLookup::class, fn () => new class($slug, $minutes) implements SessionPolicyLookup
    {
        public function __construct(private string $slug, private ?int $minutes) {}

        public function idleMinutesFor(string $organizationSlug): ?int
        {
            return $organizationSlug === $this->slug ? $this->minutes : null;
        }
    });
}

it('starts the clock the first time it sees a signed-in session', function () {
    $this->freezeTime();

    browse()->assertOk();

    expect(session(EnforceSessionPolicy::STARTED))->toBe(now()->getTimestamp())
        ->and(session(EnforceSessionPolicy::LAST_SEEN))->toBe(now()->getTimestamp());
});

it('keeps the start and moves the last seen time', function () {
    $this->freezeTime();
    browse();
    $started = session(EnforceSessionPolicy::STARTED);

    $this->travel(3)->days();
    browse()->assertOk();

    expect(session(EnforceSessionPolicy::STARTED))->toBe($started)
        ->and(session(EnforceSessionPolicy::LAST_SEEN))->toBe(now()->getTimestamp());
});

it('ends a session idle for more than seven days and sends a page request to sign in', function () {
    browse();
    $this->travel(7)->days();
    $this->travel(1)->minutes();

    browse()->assertRedirect('/signin');

    $this->assertGuest('web');
});

it('brings the person back to the page they asked for after signing in again', function () {
    browse();
    $this->travel(8)->days();
    browse('/account/security')->assertRedirect('/signin');

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/account/security');
});

it('says why, naming the limit', function () {
    browse();
    $this->travel(8)->days();

    browse()->assertRedirect('/signin')->assertSessionHas('toast.message', 'Your session ended after 7 days of inactivity.');
});

it('does not end a session that has been idle less than seven days', function () {
    browse();
    $this->travel(6)->days();

    browse()->assertOk();
});

it('ends a session after thirty days even if it was used every day', function () {
    browse();

    foreach (range(1, 6) as $n) {
        $this->travel(5)->days();
        browse()->assertOk();
    }
    $this->travel(1)->days();

    browse()->assertRedirect('/signin');
});

it('answers an Inertia or JSON request with 409 SESSION_EXPIRED and the limit, not a redirect', function (array $headers) {
    browse();
    $this->travel(8)->days();

    $response = $this->actingAs($this->user)->get('/account/security', $headers);

    $response->assertStatus(409)->assertJsonPath('error.code', 'SESSION_EXPIRED')
        ->assertJsonPath('error.details.reason', 'idle')
        ->assertJsonPath('error.details.limit_minutes', 10080)
        ->assertJsonPath('error.message', 'Your session ended after 7 days of inactivity.');
    $this->assertGuest('web');
})->with([
    'inertia' => [['X-Inertia' => 'true', 'X-Inertia-Version' => 'x']],
    'json' => [['Accept' => 'application/json']],
]);

it('names the absolute limit when that is what ended it', function () {
    browse();
    $this->travel(31)->days();

    $this->actingAs($this->user)->getJson('/account/security')->assertStatus(409)
        ->assertJsonPath('error.details.reason', 'absolute')
        ->assertJsonPath('error.message', 'Your session ended after 30 days.');
});

it('lets an organization shorten the idle limit, only inside that organization', function () {
    with_organization_limit('acme', 720);
    browse();
    $this->travel(13)->hours();

    // Elsewhere the platform limit still applies, so the session is still good.
    browse('/account/security')->assertOk();

    $this->travel(13)->hours();
    $this->actingAs($this->user)->getJson('/acme')->assertStatus(409)->assertJsonPath('error.message', 'Your session ended after 12 hours of inactivity.');
});

it('does not let an organization lengthen the limit', function () {
    with_organization_limit('acme', 99999);
    browse();
    $this->travel(8)->days();

    $this->actingAs($this->user)->getJson('/acme')->assertStatus(409)->assertJsonPath('error.details.limit_minutes', 10080);
});

it('leaves guests alone', function () {
    $this->get('/signin')->assertOk();
});

it('puts no person in the response to an ended session', function () {
    browse();
    $this->travel(8)->days();

    $response = $this->actingAs($this->user)->getJson('/account/security');

    expect($response->getContent())->not->toContain('maya@acme.dev');
});

it('records when a person was last active, at most every five minutes', function () {
    $this->freezeTime();
    browse();
    $first = $this->user->fresh()->last_active_at;

    $this->travel(4)->minutes();
    browse();
    expect($this->user->fresh()->last_active_at->equalTo($first))->toBeTrue();

    $this->travel(2)->minutes();
    browse();
    expect($this->user->fresh()->last_active_at->greaterThan($first))->toBeTrue();
});
