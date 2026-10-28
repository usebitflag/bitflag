<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    Redis::flushdb();
    RateLimiter::clear('ip|127.0.0.1');
    $this->world = portalWorld();
    $this->page = '/acme/storefront/production/settings/danger';
});

function storefront_status(): string
{
    return Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'storefront')->firstOrFail()->status->value);
}

describe('the Danger zone', function () {
    it('shows to everyone who can read the project, actionable for Owners only', function () {
        $this->actingAs($this->world['maya'])->get($this->page)->assertInertia(fn (Assert $page) => $page->component('project/settings/Danger')->where('canManage', true));
        $this->actingAs($this->world['ana'])->get($this->page)->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    });

    it('archives, unarchives, schedules a deletion with the key typed and restores', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/archive")->assertRedirect();
        expect(storefront_status())->toBe('archived');

        $this->actingAs($this->world['maya'])->post("{$this->page}/unarchive")->assertRedirect();
        expect(storefront_status())->toBe('active');

        $this->actingAs($this->world['maya'])->post("{$this->page}/delete", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->world['maya'])->post("{$this->page}/delete", ['confirm' => 'storefront'])->assertRedirect();
        expect(storefront_status())->toBe('deletion_scheduled');

        $this->actingAs($this->world['maya'])->get('/acme/storefront/production/overview')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scope.project.status', 'deletion_scheduled')->where('scope.project.deletionDueAt', fn ($at) => is_int($at) && $at > time()));

        $this->actingAs($this->world['maya'])->post("{$this->page}/restore")->assertRedirect();
        expect(storefront_status())->toBe('active');
    });

    it('refuses anyone who is not an Owner', function () {
        foreach (['jon', 'ana'] as $name) {
            foreach (['archive', 'unarchive', 'restore'] as $action) {
                $this->actingAs($this->world[$name])->post("{$this->page}/{$action}")->assertForbidden();
            }
            $this->actingAs($this->world[$name])->post("{$this->page}/delete", ['confirm' => 'storefront'])->assertForbidden();
        }

        expect(storefront_status())->toBe('active');
    });

    it('answers a read-only project with its error code when settings are saved', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/archive");

        $this->actingAs($this->world['maya'])->putJson('/acme/storefront/production/settings/general', ['name' => 'X', 'key' => 'storefront', 'time_zone' => 'UTC', 'flag_key_format' => 'kebab', 'stale_after_days' => 30, 'require_flag_description' => true, 'require_flag_owner' => true, 'client_visible_by_default' => false, 'version' => 1])
            ->assertForbidden()->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
    });

    it('does not reach another organization\'s project', function () {
        $this->actingAs($this->world['maya'])->post('/globex/billing/production/settings/danger/archive')->assertNotFound();
    });
});

describe('organization deletion from General', function () {
    it('lets an Org Owner schedule and restore it, and refuses a Member', function () {
        $this->actingAs($this->world['jon'])->post('/acme/settings/general/delete', ['confirm' => 'acme'])->assertForbidden();

        $this->actingAs($this->world['maya'])->post('/acme/settings/general/delete', ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->world['maya'])->post('/acme/settings/general/delete', ['confirm' => 'acme'])->assertRedirect();

        $this->actingAs($this->world['maya'])->get('/acme/settings/general')->assertInertia(fn (Assert $page) => $page
            ->where('settings.status', 'deletion_scheduled')->where('settings.deletionDueAt', fn ($at) => $at > time()));
        expect(storefront_status())->toBe('deletion_scheduled');

        $this->actingAs($this->world['maya'])->post('/acme/settings/general/restore')->assertRedirect();
        expect(Organization::query()->where('slug', 'acme')->firstOrFail()->status->value)->toBe('active')->and(storefront_status())->toBe('active');
    });
});
