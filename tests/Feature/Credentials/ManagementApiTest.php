<?php

declare(strict_types=1);

use App\Modules\Credentials\Actions\FlushCredentialLastUsed;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Cache::flush();
    Redis::flushdb();
    RateLimiter::clear('ip|127.0.0.1');
    $this->p = person(OrgRole::Member, ProjectRole::Owner);
    Tenancy::crossTenant('inspect', function () {
        $this->p['environment']->forceFill(['key' => 'production', 'name' => 'Production'])->save();
        $policy = EnvironmentPolicy::query()->create(['organization_id' => $this->p['organization']->id, 'project_id' => $this->p['project']->id, 'environment_id' => $this->p['environment']->id, 'version' => 1, 'is_protected' => true, 'require_reason' => true]);
        $this->p['environment']->forceFill(['current_policy_id' => $policy->id])->save();
    });
});

function bearer(string $secret, array $headers = []): array
{
    return ['Authorization' => "Bearer {$secret}", 'Accept' => 'application/json', ...$headers];
}

function management(array $p, array $scopes = ['flags:read']): array
{
    return make_credential($p, ['scopes' => $scopes], CredentialType::Management);
}

describe('authenticating', function () {
    it('answers a request without a token with UNAUTHENTICATED', function () {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertJsonStructure(['error' => ['request_id']]);
    });

    it('refuses SDK keys with WRONG_CREDENTIAL_TYPE', function (CredentialType $type) {
        $key = make_credential($this->p, [], $type);

        $this->getJson('/api/v1/me', bearer($key['secret']))->assertUnauthorized()->assertJsonPath('error.code', 'WRONG_CREDENTIAL_TYPE');
    })->with([[CredentialType::Server], [CredentialType::Browser]]);

    it('answers garbage, a bad checksum and an unknown secret with the same INVALID_CREDENTIAL body', function () {
        $management = management($this->p);
        $bodies = [];

        foreach (['nope', substr($management['secret'], 0, -1).($management['secret'][-1] === 'a' ? 'b' : 'a'), (new TokenGenerator)->generate(CredentialType::Management, 'dev')->secret] as $secret) {
            $response = $this->getJson('/api/v1/me', bearer($secret))->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIAL')->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
            $bodies[] = $response->json('error.message');
        }

        expect(array_unique($bodies))->toHaveCount(1);
    });

    it('answers a revoked, expired or rotated-out token with CREDENTIAL_REVOKED', function (array $change) {
        $key = make_credential($this->p, ['scopes' => ['flags:read'], ...$change], CredentialType::Management);

        $this->getJson('/api/v1/me', bearer($key['secret']))->assertUnauthorized()->assertJsonPath('error.code', 'CREDENTIAL_REVOKED');
    })->with([
        'revoked' => [['status' => CredentialStatus::Revoked]],
        'expired' => [['expires_at' => '2020-01-01 00:00:00']],
        'rotated out' => [['status' => CredentialStatus::Rotating, 'overlap_ends_at' => '2020-01-01 00:00:00']],
    ]);

    it('rejects a token on the very next request after it is revoked, with a warm cache', function () {
        $key = management($this->p);
        $this->getJson('/api/v1/me', bearer($key['secret']))->assertOk();

        $this->actingAs($this->p['user'])->post('/'.$this->p['organization']->slug.'/'.$this->p['project']->key.'/production/settings/environments/production/keys/'.PublicId::encode(IdPrefix::Key, $key['credential']->id).'/revoke');
        auth()->logout();

        $this->getJson('/api/v1/me', bearer($key['secret']))->assertUnauthorized()->assertJsonPath('error.code', 'CREDENTIAL_REVOKED');
    });

    it('stops a personal token when its owner is deactivated or leaves the project', function () {
        $key = make_credential($this->p, ['scopes' => ['flags:read']], CredentialType::Personal);
        $this->getJson('/api/v1/me', bearer($key['secret']))->assertOk();

        Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $this->p['user']->id)->delete());
        $this->getJson('/api/v1/me', bearer($key['secret']))->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIAL');
    });

    it('stops a personal token of someone who has not enrolled in a required two-factor', function () {
        $key = make_credential($this->p, ['scopes' => ['flags:read']], CredentialType::Personal);
        Tenancy::crossTenant('inspect', fn () => Organization::query()->whereKey($this->p['organization']->id)->update(['require_mfa' => true]));

        $this->getJson('/api/v1/me', bearer($key['secret']))->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
    });
});

describe('/api/v1/me', function () {
    it('describes a management key without its secret', function () {
        $key = management($this->p);

        $response = $this->getJson('/api/v1/me', bearer($key['secret']))->assertOk()
            ->assertJsonPath('type', 'management')->assertJsonPath('label', 'Checkout API')->assertJsonPath('scopes', ['flags:read'])
            ->assertJsonPath('user', null);

        expect($response->json('id'))->toStartWith('key_')->and($response->json('environment'))->toStartWith('env_')->and($response->getContent())->not->toContain($key['secret']);
    });

    it('describes a personal token and its owner', function () {
        $key = make_credential($this->p, ['scopes' => ['flags:read']], CredentialType::Personal);

        $this->getJson('/api/v1/me', bearer($key['secret']))->assertOk()->assertJsonPath('type', 'personal')->assertJsonPath('environment', null)->assertJsonPath('user.name', $this->p['user']->name);
    });

    it('logs out by revoking the presenting token', function () {
        $key = management($this->p);

        $this->deleteJson('/api/v1/me/token', [], bearer($key['secret']))->assertNoContent();
        $this->getJson('/api/v1/me', bearer($key['secret']))->assertUnauthorized()->assertJsonPath('error.code', 'CREDENTIAL_REVOKED');
    });

    it('records the use without a write per request and notes the client', function () {
        $key = management($this->p);

        DB::flushQueryLog();
        DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/me', bearer($key['secret'], ['X-BitFlag-Client' => 'bitflag-cli/1.8.2']))->assertOk();
        }
        $updates = array_filter(DB::getQueryLog(), fn (array $query) => preg_match('/^\s*update\s+`credentials`/i', $query['query']) === 1);

        expect($updates)->toBe([]);
        app(FlushCredentialLastUsed::class)->handle();
        expect(credential_now($key['credential'])->last_used_client)->toBe('bitflag-cli/1.8.2');
    });
});

describe('projects and environments', function () {
    it('returns the project by ID or key with the flag defaults', function () {
        $key = management($this->p);
        $id = PublicId::encode(IdPrefix::Project, $this->p['project']->id);

        foreach ([$id, $this->p['project']->key] as $address) {
            $this->getJson("/api/v1/projects/{$address}", bearer($key['secret']))->assertOk()->assertJsonPath('id', $id)->assertJsonPath('flag_defaults.key_format', 'kebab')->assertJsonPath('flag_defaults.stale_after_days', 30);
        }
    });

    it('lists and shows environments with their protection summary', function () {
        $key = management($this->p);
        Environment::factory()->create(['project_id' => $this->p['project']->id, 'organization_id' => $this->p['organization']->id, 'key' => 'dev', 'position' => 2]);

        $this->getJson("/api/v1/projects/{$this->p['project']->key}/environments", bearer($key['secret']))->assertOk()
            ->assertJsonPath('data.0.key', 'production')->assertJsonPath('data.0.protection.protected', true)->assertJsonPath('data.0.protection.policy_version', 1)->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/projects/{$this->p['project']->key}/environments/production", bearer($key['secret']))->assertOk()->assertJsonPath('name', 'Production');
        $this->getJson("/api/v1/projects/{$this->p['project']->key}/environments/nope", bearer($key['secret']))->assertNotFound();
    });

    it('needs the flags:read scope', function () {
        $key = management($this->p, ['flags:write']);

        $this->getJson("/api/v1/projects/{$this->p['project']->key}", bearer($key['secret']))->assertForbidden();
    });

    it('does not reveal projects of another organization, by ID or by key', function () {
        $other = person(OrgRole::Member, ProjectRole::Owner);
        $key = management($this->p);

        $this->getJson('/api/v1/projects/'.PublicId::encode(IdPrefix::Project, $other['project']->id), bearer($key['secret']))->assertNotFound();
        $this->getJson("/api/v1/projects/{$other['project']->key}", bearer($key['secret']))->assertNotFound();
        $this->getJson("/api/v1/projects/{$other['project']->key}/environments", bearer($key['secret']))->assertNotFound();
    });

    it('does not reveal another project of the same organization either', function () {
        $second = Project::factory()->create(['organization_id' => $this->p['organization']->id, 'key' => 'second']);
        $key = management($this->p);

        $this->getJson('/api/v1/projects/second', bearer($key['secret']))->assertNotFound();
    });
});

describe('rate limits', function () {
    it('answers 429 RATE_LIMITED with Retry-After past 600 requests a minute per credential', function () {
        $key = management($this->p);
        $key2 = management($this->p);

        for ($i = 0; $i < 600; $i++) {
            $this->getJson('/api/v1/me', bearer($key['secret']))->assertOk();
        }

        $this->getJson('/api/v1/me', bearer($key['secret']))->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->getJson('/api/v1/me', bearer($key2['secret']))->assertOk();
    });
});
