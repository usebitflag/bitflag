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

    // Every environment has a policy, and production is protected, as in a project created in the portal.
    Tenancy::crossTenant('inspect', function () {
        $environments = Environment::query()->where('project_id', Project::query()->where('key', 'storefront')->firstOrFail()->id)->get();

        foreach ($environments as $environment) {
            $protected = $environment->key === 'production';
            $policy = EnvironmentPolicy::query()->create(['organization_id' => $environment->organization_id, 'project_id' => $environment->project_id, 'environment_id' => $environment->id, 'version' => 1, 'is_protected' => $protected, 'require_reason' => $protected]);
            $environment->forceFill(['current_policy_id' => $policy->id])->save();
        }
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

describe('the Settings tab', function () {
    function settings_form(array $change = []): array
    {
        return [
            'name' => 'Staging',
            'key' => 'staging',
            'color' => 'lilac',
            'allowed_origins' => [],
            'max_snapshot_age' => null,
            'protected' => false,
            'required_approvals' => 0,
            'version' => 1,
            ...$change,
        ];
    }

    it('shows the settings, editable to an Owner and read-only to everyone else', function () {
        $this->actingAs($this->world['maya'])->get("{$this->page}/staging/settings")->assertInertia(fn (Assert $page) => $page
            ->component('project/settings/environments/Show')->where('tab', 'settings')->where('canManage', true)->where('approvalsAvailable', true)
            ->where('environment.allowedOrigins', [])->where('environment.version', 1));

        $this->actingAs($this->world['jon'])->get("{$this->page}/staging/settings")->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    });

    it('lets an Owner save, going to the address of the new key', function () {
        $this->actingAs($this->world['maya'])->put("{$this->page}/staging/settings", settings_form(['name' => 'Pre-prod', 'key' => 'pre-prod', 'allowed_origins' => ['https://app.example.com'], 'max_snapshot_age' => 3600, 'protected' => true]))
            ->assertRedirect("{$this->page}/pre-prod/settings");

        $this->actingAs($this->world['maya'])->get("{$this->page}/pre-prod/settings")->assertInertia(fn (Assert $page) => $page
            ->where('environment.name', 'Pre-prod')->where('environment.isProtected', true)->where('environment.policyVersion', 2)
            ->where('environment.allowedOrigins', ['https://app.example.com'])->where('environment.defaultMaxSnapshotAgeSeconds', 3600));
    });

    it('refuses anyone who is not an Owner', function () {
        foreach (['jon', 'ana'] as $name) {
            $this->actingAs($this->world[$name])->put("{$this->page}/staging/settings", settings_form(['name' => 'Nope']))->assertForbidden();
        }
    });

    it('shows field errors and asks to reload on a stale version', function () {
        $this->actingAs($this->world['maya'])->put("{$this->page}/staging/settings", settings_form(['allowed_origins' => ['nope']]))->assertSessionHasErrors('allowed_origins');

        $this->actingAs($this->world['maya'])->put("{$this->page}/staging/settings", settings_form(['name' => 'First']));
        $this->actingAs($this->world['maya'])->put("{$this->page}/staging/settings", settings_form(['name' => 'Second']))->assertSessionHasErrors('version');
    });

    it('is not found for an environment of another project', function () {
        $this->actingAs($this->world['maya'])->put('/acme/storefront/production/settings/environments/billing/settings', settings_form())->assertNotFound();
    });
});

describe('archiving and deleting from the Settings tab', function () {
    it('archives with the key typed and goes back to the list under another environment', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/staging/archive", ['confirm' => 'staging'])->assertRedirect($this->page);

        $this->actingAs($this->world['maya'])->get($this->page)->assertInertia(fn (Assert $page) => $page
            ->where('environments.2.key', 'staging')->where('environments.2.status', 'archived'));
    });

    it('moves the list to another environment when the open one is archived', function () {
        $this->actingAs($this->world['maya'])->post('/acme/storefront/staging/settings/environments/staging/archive', ['confirm' => 'staging'])
            ->assertRedirect('/acme/storefront/development/settings/environments');
    });

    it('refuses a wrong confirmation and anyone who is not an Owner', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/staging/archive", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->world['jon'])->post("{$this->page}/staging/archive", ['confirm' => 'staging'])->assertForbidden();
        $this->actingAs($this->world['jon'])->delete("{$this->page}/staging", ['confirm' => 'staging'])->assertForbidden();
    });

    it('deletes an unused environment and shows the blockers of a used one', function () {
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/staging/keys", ['type' => 'server', 'label' => 'A', 'scopes' => ['snapshot:read']])->assertCreated();

        $this->actingAs($this->world['maya'])->get("{$this->page}/staging/settings")->assertInertia(fn (Assert $page) => $page
            ->where('deleteBlockers', ['1 active SDK key', 'Credential history for this environment'])->where('activeEnvironmentCount', 3));

        $this->actingAs($this->world['maya'])->delete("{$this->page}/staging", ['confirm' => 'staging'])->assertSessionHasErrors('environment');
        $this->actingAs($this->world['maya'])->delete("{$this->page}/development", ['confirm' => 'development'])->assertRedirect();

        expect(Tenancy::crossTenant('inspect', fn () => Environment::query()->where('project_id', Project::query()->where('key', 'storefront')->firstOrFail()->id)->where('key', 'development')->count()))->toBe(0);
    });
});
