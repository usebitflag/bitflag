<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Projects\Models\Project;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->world = portalWorld();
    $this->page = '/acme/storefront/production/settings/environments';

    // Production is protected, as a project created in the portal has it.
    Tenancy::crossTenant('inspect', function () {
        $production = Environment::query()->where('project_id', Project::query()->where('key', 'storefront')->firstOrFail()->id)->where('key', 'production')->firstOrFail();
        $policy = EnvironmentPolicy::query()->create(['organization_id' => $production->organization_id, 'project_id' => $production->project_id, 'environment_id' => $production->id, 'version' => 1, 'is_protected' => true, 'require_reason' => true]);
        $production->forceFill(['current_policy_id' => $policy->id])->save();
    });
});

function environment_id(string $key, string $project = 'storefront'): string
{
    return Tenancy::crossTenant('inspect', fn () => Environment::query()->where('project_id', Project::query()->where('key', $project)->firstOrFail()->id)->where('key', $key)->firstOrFail()->id);
}

describe('the environments list', function () {
    it('lists every environment in order for everyone who can read the project', function () {
        foreach (['maya' => true, 'jon' => false, 'ana' => false] as $name => $canManage) {
            $this->actingAs($this->world[$name])->get($this->page)->assertInertia(fn (Assert $page) => $page
                ->component('project/settings/environments/Index')->where('canManage', $canManage)
                ->has('environments', 3)->where('environments.0.key', 'development')->where('environments.2.key', 'production')
                ->where('environments.2.isProtected', true)->where('environments.2.id', PublicId::encode(IdPrefix::Environment, environment_id('production'))));
        }
    });

    it('does not show another organization\'s environments', function () {
        $this->actingAs($this->world['maya'])->get('/globex/billing/production/settings/environments')->assertNotFound();
    });
});

describe('creating and ordering', function () {
    it('lets an Owner create an environment and opens it', function () {
        $this->actingAs($this->world['maya'])->post($this->page, ['name' => 'QA', 'key' => 'qa', 'color' => 'indigo', 'after' => PublicId::encode(IdPrefix::Environment, environment_id('staging')), 'protected' => false])
            ->assertRedirect("{$this->page}/qa");

        $keys = Tenancy::crossTenant('inspect', fn () => Environment::query()->where('project_id', Project::query()->where('key', 'storefront')->firstOrFail()->id)->orderBy('position')->pluck('key')->all());

        expect($keys)->toBe(['development', 'staging', 'qa', 'production']);
    });

    it('shows field errors', function () {
        $this->actingAs($this->world['maya'])->post($this->page, ['name' => 'Staging again', 'key' => 'staging', 'color' => 'slate', 'protected' => false])->assertSessionHasErrors('key');
    });

    it('refuses anyone who is not an Owner', function () {
        foreach (['jon', 'ana'] as $name) {
            $this->actingAs($this->world[$name])->post($this->page, ['name' => 'QA', 'key' => 'qa', 'color' => 'slate', 'protected' => false])->assertForbidden();
            $this->actingAs($this->world[$name])->put("{$this->page}/order", ['order' => [PublicId::encode(IdPrefix::Environment, environment_id('staging'))]])->assertForbidden();
        }
    });

    it('reorders from the list', function () {
        $order = array_map(fn (string $key) => PublicId::encode(IdPrefix::Environment, environment_id($key)), ['production', 'staging', 'development']);

        $this->actingAs($this->world['maya'])->put("{$this->page}/order", ['order' => $order])->assertRedirect();

        $this->actingAs($this->world['maya'])->get($this->page)->assertInertia(fn (Assert $page) => $page->where('environments.0.key', 'production'));
    });

    it('reports a stale list instead of saving it', function () {
        $order = array_map(fn (string $key) => PublicId::encode(IdPrefix::Environment, environment_id($key)), ['staging', 'development']);

        $this->actingAs($this->world['maya'])->put("{$this->page}/order", ['order' => $order])->assertSessionHasErrors('order');
    });

    it('answers a malformed identifier with not found', function () {
        $this->actingAs($this->world['maya'])->put("{$this->page}/order", ['order' => ['nope']])->assertNotFound();
    });
});

describe('one environment', function () {
    it('opens each tab', function () {
        foreach (['', '/overview', '/delivery', '/history'] as $tab) {
            $this->actingAs($this->world['ana'])->get("{$this->page}/production{$tab}")->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('project/settings/environments/Show')->where('environment.key', 'production'));
        }
    });

    it('lists the environment\'s history on the History tab', function () {
        $this->actingAs($this->world['maya'])->post($this->page, ['name' => 'QA', 'key' => 'qa', 'color' => 'slate', 'protected' => false]);

        $this->actingAs($this->world['maya'])->get("{$this->page}/qa/history")->assertInertia(fn (Assert $page) => $page
            ->has('history', 1)->where('history.0.action', 'environment.created')->where('history.0.actor', 'Maya Santos'));
    });

    it('is not found for an unknown environment, an unknown tab or another project\'s environment', function () {
        $this->actingAs($this->world['maya'])->get("{$this->page}/nope")->assertNotFound();
        $this->actingAs($this->world['maya'])->get("{$this->page}/production/nope")->assertNotFound();
        $this->actingAs($this->world['maya'])->get('/acme/checkout-api/production/settings/environments/billing')->assertNotFound();
    });
});
