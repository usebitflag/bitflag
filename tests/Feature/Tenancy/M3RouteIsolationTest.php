<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Projects\Models\Project;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route as Router;

/**
 * Every project settings route and every management API route, generated from the route list, must answer 404 to someone from
 * another organization: they must not learn that the project, environment or credential exists, and must not change it.
 */
beforeEach(function () {
    Cache::flush();
    Redis::flushdb();
    $this->world = portalWorld();
    Tenancy::crossTenant('inspect', function () {
        foreach (Environment::query()->get() as $environment) {
            $policy = EnvironmentPolicy::query()->create(['organization_id' => $environment->organization_id, 'project_id' => $environment->project_id, 'environment_id' => $environment->id, 'version' => 1]);
            $environment->forceFill(['current_policy_id' => $policy->id])->save();
        }
    });
});

/**
 * @return list<Route>
 */
function routes_named(string $prefix): array
{
    return array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $route) => str_starts_with((string) $route->getName(), $prefix)));
}

function fill_parameters(Route $route, array $address): array
{
    $ulid = str_repeat('0', 26);
    $defaults = [
        ...$address,
        'target' => 'staging', 'tab' => 'overview', 'credential' => 'key_'.$ulid, 'membership' => $ulid, 'invitation' => $ulid,
        'export' => $ulid, 'projectId' => 'prj_'.$ulid,
    ];

    return collect($route->parameterNames())->mapWithKeys(fn (string $name) => [$name => $defaults[$name] ?? 'x'])->all();
}

it('answers 404 to a member of another organization on every project settings route', function () {
    $routes = routes_named('project.settings.');
    expect(count($routes))->toBeGreaterThan(25);

    // Globex's Owner against Acme's project, and Acme's Owner against Globex's project.
    foreach ([['gina', ['organization' => 'acme', 'project' => 'storefront', 'environment' => 'production']], ['maya', ['organization' => 'globex', 'project' => 'billing', 'environment' => 'production']]] as [$person, $address]) {
        foreach ($routes as $route) {
            $method = collect($route->methods())->first(fn (string $method) => ! in_array($method, ['HEAD', 'OPTIONS'], true));
            $url = route($route->getName(), fill_parameters($route, $address), false);

            $this->actingAs($this->world[$person])->call($method, $url, [])->assertNotFound("{$method} {$url} ({$route->getName()}) answered something other than 404 to {$person}");
        }
    }
});

it('answers 404 for the personal token routes with another person\'s token', function () {
    $mine = $this->actingAs($this->world['maya'])->postJson('/acme/storefront/production/settings/tokens', ['label' => 'Mine', 'access' => 'read', 'ttl_days' => 90, 'password' => 'password'])->json('token.id');

    $this->actingAs($this->world['gina'])->post("/account/tokens/{$mine}/revoke")->assertNotFound();
    $this->actingAs($this->world['gina'])->get('/account/tokens')->assertInertia(fn ($page) => $page->has('tokens', 0));
});

it('answers 404 to a token of another organization on every management API route that names a project', function () {
    $project = Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'storefront')->firstOrFail());
    $gina = $this->world['gina'];
    $env = Tenancy::crossTenant('inspect', fn () => Environment::query()->where('organization_id', $this->world['globex']->id)->firstOrFail());
    $key = make_credential(['organization' => $this->world['globex'], 'project' => Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'billing')->firstOrFail()), 'environment' => $env, 'user' => $gina], ['scopes' => ['flags:read']], CredentialType::Management);

    $routes = array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $route) => str_starts_with($route->uri(), 'api/v1/projects/{project}')));
    expect(count($routes))->toBeGreaterThanOrEqual(3);

    foreach ($routes as $route) {
        foreach (['storefront', PublicId::encode(IdPrefix::Project, $project->id)] as $address) {
            $url = '/'.str_replace(['{project}', '{environment}'], [$address, 'production'], $route->uri());

            $this->getJson($url, ['Authorization' => 'Bearer '.$key['secret']])->assertNotFound("GET {$url} answered something other than 404 to a Globex token");
        }
    }
});
