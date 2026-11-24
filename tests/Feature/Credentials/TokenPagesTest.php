<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Environments\Models\Environment;
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
    $this->page = '/acme/storefront/production/settings/tokens';
});

function token_form(array $change = []): array
{
    return ['label' => 'Laptop CLI', 'access' => 'draft', 'ttl_days' => 90, 'password' => 'password', ...$change];
}

describe('making a token from the API tokens page', function () {
    it('returns the secret once and asks for the password', function () {
        $this->actingAs($this->world['jon'])->postJson($this->page, token_form(['password' => 'wrong']))->assertUnprocessable()->assertJsonPath('error.details.fields.0.path', 'password');

        $response = $this->actingAs($this->world['jon'])->postJson($this->page, token_form())->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $secret = $response->json('secret');

        expect($secret)->toStartWith('bf_pat_')
            ->and($response->json('token.access'))->toBe('draft')
            ->and(json_encode($response->json('token')))->not->toContain($secret);

        $this->actingAs($this->world['jon'])->get($this->page)->assertInertia(fn (Assert $page) => $page
            ->component('project/settings/Tokens')->has('tokens', 1)->where('tokens.0.masked', $response->json('token.masked')))->assertDontSee($secret, false);

        expect(app(CredentialResolver::class)->resolve($secret)->type)->toBe(CredentialType::Personal);
    });

    it('refuses an access level the role cannot have', function () {
        $this->actingAs($this->world['ana'])->postJson($this->page, token_form(['access' => 'draft']))->assertUnprocessable()->assertJsonPath('error.details.fields.0.path', 'access');
        $this->actingAs($this->world['ana'])->postJson($this->page, token_form(['access' => 'read']))->assertCreated();
    });

    it('tells the page what the role can offer', function () {
        $this->actingAs($this->world['ana'])->get($this->page)->assertInertia(fn (Assert $page) => $page->where('canDraft', false)->where('canPublish', false));
        $this->actingAs($this->world['jon'])->get($this->page)->assertInertia(fn (Assert $page) => $page->where('canDraft', true)->where('canPublish', true));
    });

    it('shows only the person\'s own tokens, and the project\'s management keys read-only', function () {
        $this->actingAs($this->world['jon'])->postJson($this->page, token_form());
        $this->actingAs($this->world['maya'])->postJson($this->page, token_form(['label' => 'Maya CLI']));

        $storefront = Tenancy::crossTenant('inspect', fn () => Environment::query()->where('key', 'staging')->firstOrFail());
        Tenancy::crossTenant('inspect', fn () => Credential::factory()->create(['environment_id' => $storefront->id, 'type' => CredentialType::Management, 'label' => 'Release pipeline', 'display_prefix' => 'bf_mgt_stg_', 'scopes' => ['flags:read']]));

        $this->actingAs($this->world['jon'])->get($this->page)->assertInertia(fn (Assert $page) => $page
            ->has('tokens', 1)->where('tokens.0.label', 'Laptop CLI')->has('managementKeys', 1)->where('managementKeys.0.label', 'Release pipeline')->where('managementKeys.0.environment.key', 'staging'));
    });
});

describe('revoking', function () {
    it('lets the owner revoke their token and not someone else\'s', function () {
        $jon = $this->actingAs($this->world['jon'])->postJson($this->page, token_form())->json();
        $maya = $this->actingAs($this->world['maya'])->postJson($this->page, token_form())->json();

        $this->actingAs($this->world['jon'])->post("{$this->page}/{$maya['token']['id']}/revoke")->assertForbidden();
        $this->actingAs($this->world['jon'])->post("{$this->page}/{$jon['token']['id']}/revoke")->assertRedirect();

        $this->actingAs($this->world['jon'])->get($this->page)->assertInertia(fn (Assert $page) => $page->where('tokens.0.state', 'revoked'));
    });

    it('does not reveal a token of another project or organization', function () {
        $maya = $this->actingAs($this->world['maya'])->postJson($this->page, token_form())->json();

        $this->actingAs($this->world['maya'])->post('/acme/checkout-api/production/settings/tokens/'.$maya['token']['id'].'/revoke')->assertNotFound();
        $this->actingAs($this->world['gina'])->post('/globex/billing/production/settings/tokens/'.$maya['token']['id'].'/revoke')->assertNotFound();
    });
});

describe('the account page', function () {
    it('lists the person\'s tokens across projects and revokes one', function () {
        $first = $this->actingAs($this->world['maya'])->postJson($this->page, token_form())->json();
        $this->actingAs($this->world['maya'])->postJson('/acme/checkout-api/production/settings/tokens', token_form(['label' => 'Checkout script']))->assertCreated();
        $this->actingAs($this->world['jon'])->postJson($this->page, token_form());

        $this->actingAs($this->world['maya'])->get('/account/tokens')->assertInertia(fn (Assert $page) => $page
            ->component('account/Tokens')->has('tokens', 2)->where('tokens.0.organization.slug', 'acme'));

        $this->actingAs($this->world['maya'])->post("/account/tokens/{$first['token']['id']}/revoke")->assertRedirect();
        expect(Tenancy::crossTenant('inspect', fn () => Credential::query()->where('label', 'Laptop CLI')->where('user_id', $this->world['maya']->id)->firstOrFail()->status->value))->toBe('revoked');
    });

    it('does not let a person revoke someone else\'s token', function () {
        $jon = $this->actingAs($this->world['jon'])->postJson($this->page, token_form())->json();

        $this->actingAs($this->world['maya'])->post("/account/tokens/{$jon['token']['id']}/revoke")->assertNotFound();
    });
});
