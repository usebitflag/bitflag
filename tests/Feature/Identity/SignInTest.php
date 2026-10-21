<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Support\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
    RateLimiter::clear('maya@acme.dev|127.0.0.1');
});

it('shows the sign-in screen to a guest', function () {
    $this->get('/signin')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

it('signs a person in with the right password', function () {
    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
});

it('accepts the address in any letter case', function () {
    $this->post('/signin', ['email' => 'Maya@Acme.DEV', 'password' => 'correct horse'])->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user, 'web');
});

it('gives the same answer for a wrong password, an unknown address and a deactivated account', function () {
    User::factory()->create(['email' => 'gone@acme.dev', 'password' => 'correct horse', 'status' => 'deactivated']);
    $attempts = [
        ['maya@acme.dev', 'wrong password'],
        ['nobody@acme.dev', 'correct horse'],
        ['gone@acme.dev', 'correct horse'],
    ];
    $messages = [];

    foreach ($attempts as [$email, $password]) {
        $response = $this->from('/signin')->post('/signin', ['email' => $email, 'password' => $password]);
        $response->assertRedirect('/signin')->assertSessionHasErrors('email');
        $messages[] = session('errors')->first('email');
        $this->assertGuest('web');
    }

    expect(array_unique($messages))->toHaveCount(1);
});

it('slows repeated failures down', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'wrong'.$attempt]);
    }

    $this->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse'])->assertStatus(429);
    $this->assertGuest('web');
});

it('slows trying many different accounts from one network', function () {
    RateLimiter::clear('ip|127.0.0.1');

    foreach (range(1, 30) as $attempt) {
        $this->post('/signin', ['email' => "someone{$attempt}@acme.dev", 'password' => 'wrong'])->assertStatus(302);
    }

    $this->post('/signin', ['email' => 'another@acme.dev', 'password' => 'wrong'])->assertStatus(429);

    RateLimiter::clear('ip|127.0.0.1');
});

it('sends a signed-in person away from the sign-in screen', function () {
    $this->actingAs($this->user)->get('/signin')->assertRedirect('/');
});

it('signs a person out', function () {
    $this->actingAs($this->user)->post('/signout')->assertRedirect('/');

    $this->assertGuest('web');
});

it('does not let a guest sign out', function () {
    $this->post('/signout')->assertRedirect('/signin');
});

it('serves sign-in on the portal host only', function () {
    $this->get('http://'.config('bitflag.hosts.admin').'/signin')->assertNotFound();
});

it('keeps people and staff apart', function () {
    $staff = StaffUser::factory()->create();

    $this->actingAs($this->user, 'web');
    expect(Auth::guard('staff')->check())->toBeFalse();

    Auth::guard('web')->logout();
    $this->actingAs($staff, 'staff');
    expect(Auth::guard('web')->check())->toBeFalse()->and(Auth::guard('staff')->check())->toBeTrue();
});

it('rejects a staff password for a customer account and the other way round', function () {
    StaffUser::factory()->create(['email' => 'support@bitflag.test', 'password' => 'staff secret']);

    $this->post('/signin', ['email' => 'support@bitflag.test', 'password' => 'staff secret'])->assertSessionHasErrors('email');
    $this->assertGuest('web');
    $this->assertGuest('staff');
});
