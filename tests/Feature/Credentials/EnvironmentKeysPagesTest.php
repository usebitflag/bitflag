<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
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
    $this->page = '/acme/storefront/production/settings/environments/staging';
    Tenancy::crossTenant('inspect', function () {
        foreach (Environment::query()->get() as $environment) {
            $policy = EnvironmentPolicy::query()->create(['organization_id' => $environment->organization_id, 'project_id' => $environment->project_id, 'environment_id' => $environment->id, 'version' => 1]);
            $environment->forceFill(['current_policy_id' => $policy->id])->save();
        }
    });
});

function key_form(array $change = []): array
{
    return ['type' => 'server', 'label' => 'Checkout API', 'scopes' => ['snapshot:read'], 'ttl_days' => null, ...$change];
}

function staging_credentials(): array
{
    return Tenancy::crossTenant('inspect', fn () => Credential::query()->get()->all());
}

describe('creating a key', function () {
    it('returns the secret once, as JSON that cannot be cached, and nothing else carries it', function () {
        $response = $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form())->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private');
        $secret = $response->json('secret');

        expect($secret)->toStartWith('bf_srv_stg_')
            ->and($response->json('credential.masked'))->toBe('bf_srv_stg_••••'.substr($secret, -4))
            ->and(json_encode($response->json('credential')))->not->toContain($secret);

        // Reloading shows only the masked key.
        $this->actingAs($this->world['maya'])->get("{$this->page}/keys")->assertInertia(fn (Assert $page) => $page
            ->component('project/settings/environments/Show')->where('tab', 'keys')->has('keys', 1)->where('keys.0.masked', $response->json('credential.masked')))
            ->assertDontSee($secret, false);

        expect(app(CredentialResolver::class)->resolve($secret)->scopes)->toBe(['snapshot:read']);
    });

    it('refuses anyone who does not manage credentials', function () {
        foreach (['jon', 'ana'] as $name) {
            $this->actingAs($this->world[$name])->postJson("{$this->page}/keys", key_form())->assertForbidden();
        }

        expect(staging_credentials())->toBe([]);
    });

    it('answers invalid values with field errors', function () {
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form(['scopes' => ['publish']]))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonPath('error.details.fields.0.path', 'scopes');
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form(['label' => '']))->assertUnprocessable()->assertJsonPath('error.details.fields.0.path', 'label');
    });

    it('is not found in another organization\'s or an unknown environment', function () {
        $this->actingAs($this->world['maya'])->postJson('/acme/storefront/production/settings/environments/nope/keys', key_form())->assertNotFound();
        $this->actingAs($this->world['maya'])->postJson('/globex/billing/production/settings/environments/staging/keys', key_form())->assertNotFound();
    });
});

describe('rotating and revoking from the Keys tab', function () {
    it('rotates with an overlap and returns the replacement\'s secret', function () {
        $old = $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form())->json();

        $rotated = $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys/{$old['credential']['id']}/rotate", ['overlap' => 3600])->assertCreated();

        expect($rotated->json('secret'))->not->toBe($old['secret'])
            ->and($rotated->json('oldStopsAt'))->toBeGreaterThan(time())
            ->and(app(CredentialResolver::class)->resolve($old['secret'])->status->value)->toBe('rotating');

        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys/{$old['credential']['id']}/rotate", ['overlap' => 3600])->assertUnprocessable();
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys/{$rotated->json('credential.id')}/rotate", ['overlap' => 5])->assertUnprocessable();
    });

    it('revokes at once and refuses a key from another environment', function () {
        $key = $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form())->json();

        $this->actingAs($this->world['maya'])->post("/acme/storefront/production/settings/environments/development/keys/{$key['credential']['id']}/revoke")->assertNotFound();
        $this->actingAs($this->world['jon'])->post("{$this->page}/keys/{$key['credential']['id']}/revoke")->assertForbidden();

        $this->actingAs($this->world['maya'])->post("{$this->page}/keys/{$key['credential']['id']}/revoke")->assertRedirect();

        expect(fn () => app(CredentialResolver::class)->resolve($key['secret']))->toThrow(RevokedCredential::class);
    });

    it('does not reveal another organization\'s keys by id', function () {
        $other = $this->actingAs($this->world['gina'])->postJson('/globex/billing/production/settings/environments/staging/keys', key_form())->json();

        $this->actingAs($this->world['maya'])->post("{$this->page}/keys/{$other['credential']['id']}/revoke")->assertNotFound();
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys/{$other['credential']['id']}/rotate", ['overlap' => 3600])->assertNotFound();
    });
});

describe('the Keys tab', function () {
    it('is read-only for people who cannot manage credentials and shows who created each key', function () {
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form());

        $this->actingAs($this->world['ana'])->get("{$this->page}/keys")->assertInertia(fn (Assert $page) => $page
            ->where('canManageKeys', false)->where('keys.0.createdBy.name', 'Maya Santos')->where('keys.0.createdBy.formerMember', false));
    });

    it('counts working keys in the environments list', function () {
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form());
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form(['label' => 'Second']));

        $this->actingAs($this->world['maya'])->get('/acme/storefront/production/settings/environments')->assertInertia(fn (Assert $page) => $page
            ->where('environments.1.key', 'staging')->where('environments.1.activeKeys', 2)->where('environments.0.activeKeys', 0));
    });

    it('marks a key whose creator is gone', function () {
        $this->actingAs($this->world['maya'])->postJson("{$this->page}/keys", key_form());
        Tenancy::crossTenant('inspect', fn () => Credential::query()->update(['created_by' => null]));

        $this->actingAs($this->world['maya'])->get("{$this->page}/keys")->assertInertia(fn (Assert $page) => $page->where('keys.0.createdBy.formerMember', true));
    });
});
