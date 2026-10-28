<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'the old password']);
});

function change_password(array $fields = []): TestResponse
{
    return test()->actingAs(test()->user)->from('/account')->put('/account/password', [
        'current_password' => 'the old password', 'password' => 'a brand new password', ...$fields,
    ]);
}

it('changes the password, signs out other sessions and mails the person', function () {
    DB::table('sessions')->insert([
        ['id' => 'other', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'someone-else', 'user_id' => User::factory()->create()->id, 'payload' => '', 'last_activity' => time()],
    ]);

    change_password()->assertRedirect('/account');

    expect(Hash::check('a brand new password', $this->user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'someone-else')->exists())->toBeTrue()
        ->and(SecurityEvent::query()->where('type', SecurityEventType::PasswordChanged)->count())->toBe(1)
        ->and(OutboxMessageRecord::query()->where('payload->template', 'password_changed')->count())->toBe(1);
});

it('needs the current password', function () {
    change_password(['current_password' => 'not it'])->assertSessionHasErrors('current_password');

    expect(Hash::check('the old password', $this->user->fresh()->password))->toBeTrue();
});

it('applies the password rules, including the email', function () {
    change_password(['password' => 'maya@acme.dev'])->assertSessionHasErrors('password');
    change_password(['password' => 'short'])->assertSessionHasErrors('password');
});

it('revokes trusted browsers', function () {
    DB::table('trusted_browsers')->insert([
        'id' => strtolower((string) Str::ulid()), 'user_id' => $this->user->id, 'token_hash' => random_bytes(32),
        'device_label' => 'Chrome on macOS', 'created_at' => now(), 'expires_at' => now()->addDays(30),
    ]);

    change_password();

    expect(DB::table('trusted_browsers')->whereNull('revoked_at')->count())->toBe(0);
});

it('replaces the id of the session that made the change', function () {
    $this->actingAs($this->user)->get('/signin');
    $before = session()->getId();

    change_password();

    expect(session()->getId())->not->toBe($before);
});

it('is for signed-in people only', function () {
    auth('web')->logout();

    $this->put('/account/password', ['current_password' => 'x', 'password' => 'y'])->assertRedirect('/signin');
});
