<?php

declare(strict_types=1);

use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->world = portalWorld();
});

function storefront(): Project
{
    return Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'storefront')->firstOrFail());
}

function general_form(array $change = []): array
{
    return [
        'name' => 'Storefront',
        'key' => 'storefront',
        'description' => '',
        'time_zone' => 'UTC',
        'flag_key_format' => 'kebab',
        'stale_after_days' => 30,
        'require_flag_description' => true,
        'require_flag_owner' => true,
        'client_visible_by_default' => false,
        'version' => 1,
        ...$change,
    ];
}

describe('General', function () {
    it('sends the settings link to General', function () {
        $this->actingAs($this->world['maya'])->get('/acme/storefront/production/settings')->assertRedirect('/acme/storefront/production/settings/general');
    });

    it('shows the settings, editable to an Owner and read-only to everyone else', function () {
        $this->actingAs($this->world['maya'])->get('/acme/storefront/production/settings/general')->assertInertia(fn (Assert $page) => $page
            ->component('project/settings/General')->where('canManage', true)->where('settings.name', 'Storefront')
            ->where('settings.keyLocked', false)->where('settings.staleAfterDays', 30)->where('settings.version', 1));

        foreach (['jon', 'ana'] as $name) {
            $this->actingAs($this->world[$name])->get('/acme/storefront/production/settings/general')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
        }
    });

    it('lets an Owner save and goes to the address of the new key', function () {
        $this->actingAs($this->world['maya'])->put('/acme/storefront/production/settings/general', general_form(['name' => 'Web', 'key' => 'web', 'stale_after_days' => 60]))
            ->assertRedirect('/acme/web/production/settings/general');

        $project = Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'web')->firstOrFail());

        expect($project->name)->toBe('Web')->and($project->stale_after_days)->toBe(60)->and($project->version)->toBe(2);
    });

    it('refuses anyone who is not an Owner', function () {
        foreach (['jon', 'ana'] as $name) {
            $this->actingAs($this->world[$name])->put('/acme/storefront/production/settings/general', general_form(['name' => 'Nope']))->assertForbidden();
        }

        expect(storefront()->name)->toBe('Storefront');
    });

    it('shows field errors and asks to reload on a stale version', function () {
        $this->actingAs($this->world['maya'])->put('/acme/storefront/production/settings/general', general_form(['time_zone' => 'Mars/Olympus']))
            ->assertSessionHasErrors('time_zone');

        $this->actingAs($this->world['maya'])->put('/acme/storefront/production/settings/general', general_form(['name' => 'First']));
        $this->actingAs($this->world['maya'])->put('/acme/storefront/production/settings/general', general_form(['name' => 'Second']))
            ->assertSessionHasErrors('version');

        expect(Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'storefront')->value('name')))->toBe('First');
    });

    it('does not reveal another organization\'s project', function () {
        $this->actingAs($this->world['maya'])->get('/globex/billing/production/settings/general')->assertNotFound();
        $this->actingAs($this->world['maya'])->put('/globex/billing/production/settings/general', general_form())->assertNotFound();
    });
});
