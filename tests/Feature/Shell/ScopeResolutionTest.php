<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->world = portalWorld());

function archive(string $model, string $organizationId, string $key): void
{
    Tenancy::crossTenant('archive in a test', fn () => $model::query()->where('organization_id', $organizationId)->where('key', $key)->update(['status' => 'archived']));
}

describe('redirects', function () {
    it('sends a guest to sign in', function () {
        $this->get('/')->assertRedirect('/signin');
    });

    it('sends a person to the first project they can open and its first environment', function () {
        $this->actingAs($this->world['jon'])->get('/')->assertRedirect('/acme/checkout-api/development/overview');
    });

    it('sends a person to their remembered project and environment', function () {
        $production = Tenancy::crossTenant('read', fn () => Environment::query()->where('project_id', $this->world['storefront']->id)->where('key', 'production')->firstOrFail());
        $this->world['maya']->forceFill(['last_organization_id' => $this->world['acme']->id, 'last_project_id' => $this->world['storefront']->id, 'last_environment_id' => $production->id])->save();

        $this->actingAs($this->world['maya'])->get('/')->assertRedirect('/acme/storefront/production/overview');
    });

    it('falls back when the remembered project is no longer accessible', function () {
        $checkoutEnvironment = Tenancy::crossTenant('read', fn () => Environment::query()->where('project_id', $this->world['checkout']->id)->firstOrFail());
        $this->world['ana']->forceFill(['last_organization_id' => $this->world['acme']->id, 'last_project_id' => $this->world['checkout']->id, 'last_environment_id' => $checkoutEnvironment->id])->save();

        $this->actingAs($this->world['ana'])->get('/')->assertRedirect('/acme/storefront/development/overview');
    });

    it('redirects an organization to a project and a project to its first environment', function () {
        $this->actingAs($this->world['ana'])->get('/acme')->assertRedirect('/acme/storefront/development/overview');
        $this->actingAs($this->world['maya'])->get('/acme/storefront')->assertRedirect('/acme/storefront/development/overview');
    });

    it('shows the no-access page to a person who belongs to no project', function () {
        $this->actingAs($this->world['leo'])->get('/')->assertRedirect('/no-access');
        $this->actingAs($this->world['leo'])->get('/no-access')->assertOk()->assertInertia(fn (Assert $page) => $page->component('account/NoAccess'));
    });

    it('sends the organization settings link back to the organization for now', function () {
        $this->actingAs($this->world['maya'])->get('/acme/settings')->assertRedirect('/acme');
    });
});

describe('the overview page', function () {
    it('renders for a member', function () {
        $this->actingAs($this->world['ana'])->get('/acme/storefront/production/overview')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('overview/Show')
            ->where('heading', 'Overview · production')
            ->where('scope.projectRole', 'viewer')
            ->where('scope.orgRole', 'member'));
    });

    it('is a 404 for a person who is not a member of the project', function () {
        $this->actingAs($this->world['leo'])->get('/acme/storefront/production/overview')->assertNotFound();
        $this->actingAs($this->world['ana'])->get('/acme/checkout-api/production/overview')->assertNotFound();
    });

    it('is a 404 across organizations, in both directions', function () {
        $this->actingAs($this->world['gina'])->get('/acme/storefront/production/overview')->assertNotFound();
        $this->actingAs($this->world['maya'])->get('/globex/billing/production/overview')->assertNotFound();
    });

    it('is the same 404 for a project or environment that does not exist', function () {
        $this->actingAs($this->world['maya'])->get('/acme/storefront/qa/overview')->assertNotFound();
        $this->actingAs($this->world['maya'])->get('/acme/nope/production/overview')->assertNotFound();
        $this->actingAs($this->world['maya'])->get('/nope/storefront/production/overview')->assertNotFound();
    });

    it('is a 404 for an archived environment or project', function () {
        archive(Environment::class, $this->world['acme']->id, 'staging');
        $this->actingAs($this->world['maya'])->get('/acme/storefront/staging/overview')->assertNotFound();

        archive(Project::class, $this->world['acme']->id, 'checkout-api');
        $this->actingAs($this->world['maya'])->get('/acme/checkout-api/development/overview')->assertNotFound();
    });

    it('sends a guest to sign in', function () {
        $this->get('/acme/storefront/production/overview')->assertRedirect('/signin');
    });

    it('does not use application paths as organization slugs', function () {
        $this->get('/up')->assertOk();
        $this->actingAs($this->world['maya'])->get('/horizon')->assertNotFound();
    });
});

describe('remembering the last scope', function () {
    beforeEach(function () {
        $this->production = Tenancy::crossTenant('read', fn () => Environment::query()->where('project_id', $this->world['storefront']->id)->where('key', 'production')->firstOrFail());
        $this->payload = fn (array $override = []): array => [
            'organization_id' => $this->world['acme']->id,
            'project_id' => $this->world['storefront']->id,
            'environment_id' => $this->production->id,
            ...$override,
        ];
    });

    it('stores the scope for a person who can read the project', function () {
        $this->actingAs($this->world['ana'])->post('/scope/remember', ($this->payload)())->assertNoContent();

        $user = User::query()->findOrFail($this->world['ana']->id);
        expect($user->last_project_id)->toBe($this->world['storefront']->id)->and($user->last_environment_id)->toBe($this->production->id);
    });

    it('refuses a project the person cannot read, and stores nothing', function () {
        $this->actingAs($this->world['leo'])->postJson('/scope/remember', ($this->payload)())->assertForbidden();

        expect(User::query()->findOrFail($this->world['leo']->id)->last_project_id)->toBeNull();
    });

    it('rejects an environment that belongs to a different project', function () {
        $other = Tenancy::crossTenant('read', fn () => Environment::query()->where('project_id', $this->world['checkout']->id)->firstOrFail());

        $this->actingAs($this->world['maya'])->postJson('/scope/remember', ($this->payload)(['environment_id' => $other->id]))->assertNotFound();
    });

    it('rejects malformed input', function () {
        $this->actingAs($this->world['maya'])->postJson('/scope/remember', ['organization_id' => 'x'])->assertUnprocessable();
    });

    it('needs a signed-in person', function () {
        $this->postJson('/scope/remember', ($this->payload)())->assertUnauthorized();
    });
});
