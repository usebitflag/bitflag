<?php

declare(strict_types=1);

use App\Admin\AdminPanelProvider;
use App\Modules\Identity\Models\User;
use App\Modules\Support\Models\StaffUser;

function adminUrl(string $path = '/'): string
{
    return 'http://'.config('bitflag.hosts.admin').$path;
}

function portalUrl(string $path = '/'): string
{
    return 'http://'.config('bitflag.hosts.app').$path;
}

it('serves the Filament sign-in screen on the admin host', function () {
    $this->get(adminUrl('/login'))->assertOk()->assertSee('BitFlag Support');
});

it('sends a guest on the admin host to the staff sign-in', function () {
    $this->get(adminUrl('/'))->assertRedirect(adminUrl('/login'));
});

it('opens the empty dashboard for an active staff member', function () {
    $this->actingAs(StaffUser::factory()->create(), 'staff')->get(adminUrl('/'))->assertOk();
});

it('does not open the admin console for a disabled staff member', function () {
    $this->actingAs(StaffUser::factory()->create(['status' => 'disabled']), 'staff')->get(adminUrl('/'))->assertForbidden();
});

it('rejects a portal session on the admin host', function () {
    $this->actingAs(User::factory()->create(), 'web')->get(adminUrl('/'))->assertRedirect(adminUrl('/login'));
});

it('rejects a staff session on the portal host', function () {
    $this->actingAs(StaffUser::factory()->create(), 'staff')->get(portalUrl('/signin'))->assertOk();
    expect(auth('web')->check())->toBeFalse();
});

it('does not serve portal routes on the admin host or admin routes on the portal host', function () {
    $this->get(adminUrl('/signin'))->assertNotFound();
    // The portal's /login only sends people on to /signin; it is not the admin sign-in.
    $this->get(portalUrl('/login'))->assertRedirect(portalUrl('/signin'));
    $this->get(adminUrl('/login'))->assertOk();
});

it('keeps the Horizon dashboard on the admin host and behind the staff session', function () {
    $this->get(portalUrl('/horizon'))->assertNotFound();
    $this->get(adminUrl('/horizon'))->assertForbidden();

    $this->actingAs(User::factory()->create(), 'web')->get(adminUrl('/horizon'))->assertForbidden();
    $this->actingAs(StaffUser::factory()->create(), 'staff')->get(adminUrl('/horizon'))->assertOk();
    $this->actingAs(StaffUser::factory()->create(['status' => 'disabled']), 'staff')->get(adminUrl('/horizon'))->assertForbidden();
});

it('uses a separate session cookie name on the admin host', function () {
    expect(AdminPanelProvider::SESSION_COOKIE)->toBe('bitflag_staff_session')
        ->and(config('session.cookie'))->not->toBe(AdminPanelProvider::SESSION_COOKIE);
});
