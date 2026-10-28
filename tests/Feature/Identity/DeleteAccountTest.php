<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\DeleteAccount;
use App\Modules\Identity\Data\DeleteAccountData;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Events\MemberRemovedFromOrganization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->world = portalWorld();
    $this->jon = $this->world['jon'];
    $this->jon->forceFill(['password' => 'correct horse battery'])->save();
});

function delete_account(User $user, ?string $email = null, string $password = 'correct horse battery'): void
{
    app(DeleteAccount::class)->handle(new DeleteAccountData($user->id, $password, $email ?? $user->email, '127.0.0.1', 'test'));
}

describe('the page', function () {
    it('says nothing blocks a person who is not the only owner', function () {
        $this->actingAs($this->jon)->get('/account/delete')->assertInertia(fn (Assert $page) => $page
            ->component('account/Delete')->where('email', 'jon@acme.dev')->where('soleOwnerOf', []));
    });

    it('names the organizations where the person is the only owner', function () {
        $this->actingAs($this->world['maya'])->get('/account/delete')->assertInertia(fn (Assert $page) => $page->where('soleOwnerOf', ['Acme']));
    });
});

describe('deleting', function () {
    it('removes the person from every organization and project, and signs them out', function () {
        Event::fake([MemberRemovedFromOrganization::class]);
        DB::table('sessions')->insert(['id' => 'jon-session', 'user_id' => $this->jon->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->jon)->delete('/account', ['email' => 'jon@acme.dev', 'password' => 'correct horse battery'])
            ->assertRedirect('/signin')->assertSessionHas('toast.message', 'Your account was deleted.');

        $this->assertGuest('web');
        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $this->jon->id)->count()))->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->jon->id)->count()))->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $this->jon->id)->count())->toBe(0);
        Event::assertDispatched(MemberRemovedFromOrganization::class, fn ($e) => $e->userId === $this->jon->id && $e->organizationId === $this->world['acme']->id);
    });

    it('anonymizes the account but keeps the name for the history', function () {
        delete_account($this->jon);

        $user = $this->jon->fresh();

        expect($user->name)->toBe('Deleted user')
            ->and($user->deleted_display_name)->toBe('Jon Reyes')
            ->and($user->email)->toBe('deleted-'.strtolower($user->id).'@deleted.invalid')
            ->and($user->status)->toBe(AccountStatus::Deactivated)
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->pending_email)->toBeNull()
            ->and(Hash::check('correct horse battery', $user->password))->toBeFalse();
    });

    it('can no longer sign in, with the old address or the new', function () {
        delete_account($this->jon);

        $this->post('/signin', ['email' => 'jon@acme.dev', 'password' => 'correct horse battery'])->assertSessionHasErrors('email');
        $this->post('/signin', ['email' => $this->jon->fresh()->email, 'password' => 'correct horse battery'])->assertSessionHasErrors('email');
    });

    it('frees the address for a new account', function () {
        delete_account($this->jon);

        $this->post('/signup', ['email' => 'jon@acme.dev', 'password' => 'correct horse battery'])->assertRedirect('/signup/check-email');

        expect(User::query()->where('email', 'jon@acme.dev')->count())->toBe(1);
    });

    it('removes the second factor, trusted browsers and known devices', function () {
        enroll_two_factor($this->jon);
        DB::table('trusted_browsers')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $this->jon->id, 'token_hash' => random_bytes(32), 'device_label' => 'x', 'created_at' => now(), 'expires_at' => now()->addDays(30)]);
        DB::table('known_devices')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $this->jon->id, 'device_hash' => random_bytes(32), 'device_label' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        delete_account($this->jon);

        expect(MfaFactor::query()->count())->toBe(0)
            ->and(DB::table('trusted_browsers')->count())->toBe(0)
            ->and(DB::table('known_devices')->count())->toBe(0)
            ->and(DB::table('mfa_recovery_codes')->count())->toBe(0);
    });

    it('records the removal from each organization in the audit trail', function () {
        delete_account($this->jon);

        $event = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'org_member.removed')->firstOrFail());

        expect($event->details)->toMatchArray(['account_deleted' => true, 'role' => 'member']);
    });

    it('leaves other people and other organizations alone', function () {
        delete_account($this->jon);

        expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->count()))->toBe(4)
            ->and($this->world['maya']->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('needs the password', function () {
        expect(fn () => delete_account($this->jon, password: 'wrong'))->toThrow(ValidationException::class, 'password');
        expect($this->jon->fresh()->name)->toBe('Jon Reyes');
    });

    it('needs the email typed exactly, ignoring case and spaces', function () {
        expect(fn () => delete_account($this->jon, 'someone@else.dev'))->toThrow(ValidationException::class, 'exactly');

        delete_account($this->jon, '  JON@acme.DEV ');
        expect($this->jon->fresh()->name)->toBe('Deleted user');
    });

    it('is refused while the person is the only Org Owner of an organization, and changes nothing', function () {
        $maya = $this->world['maya'];
        $maya->forceFill(['password' => 'correct horse battery'])->save();

        expect(fn () => delete_account($maya))->toThrow(LastOwner::class, 'Acme');

        expect($maya->fresh()->name)->toBe('Maya Santos')
            ->and(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $maya->id)->count()))->toBe(1);
    });

    it('says why on the page', function () {
        $maya = $this->world['maya'];
        $maya->forceFill(['password' => 'correct horse battery'])->save();

        $this->actingAs($maya)->from('/account/delete')->delete('/account', ['email' => 'maya@acme.dev', 'password' => 'correct horse battery'])->assertSessionHasErrors('account');
        $this->assertAuthenticatedAs($maya, 'web');
    });

    it('is allowed once another owner exists', function () {
        $maya = $this->world['maya'];
        $maya->forceFill(['password' => 'correct horse battery'])->save();
        Tenancy::crossTenant('second owner', fn () => OrganizationMembership::query()->where('user_id', $this->jon->id)->update(['role' => 'owner']));

        delete_account($maya);

        expect($maya->fresh()->name)->toBe('Deleted user')
            ->and(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('role', 'owner')->count()))->toBe(2);
    });

    it('is for signed-in people, and limited in how often it can be tried', function () {
        $this->delete('/account', ['email' => 'x', 'password' => 'y'])->assertRedirect('/signin');

        foreach (range(1, 5) as $n) {
            $this->actingAs($this->jon)->from('/account/delete')->delete('/account', ['email' => 'jon@acme.dev', 'password' => 'wrong']);
        }
        $this->actingAs($this->jon)->delete('/account', ['email' => 'jon@acme.dev', 'password' => 'correct horse battery'])->assertStatus(429);

        expect($this->jon->fresh()->name)->toBe('Jon Reyes');
    });

    it('leaves nothing half-done when the memberships cannot be removed', function () {
        app()->bind(AuditRecorder::class, fn () => throw new RuntimeException('audit down'));

        expect(fn () => delete_account($this->jon))->toThrow(RuntimeException::class);
        expect($this->jon->fresh()->name)->toBe('Jon Reyes')
            ->and(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('user_id', $this->jon->id)->count()))->toBe(1);
    });
});
