<?php

declare(strict_types=1);

use App\Modules\Credentials\Actions\CredentialUsageRecorder;
use App\Modules\Credentials\Actions\FlushCredentialLastUsed;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\InvalidCredential;
use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Jobs\RepairCredentialCache;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Credentials\Support\CredentialCache;
use App\Modules\Credentials\Support\CredentialSummaries;
use App\Modules\Environments\Models\Environment;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Models\Project;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Psr\Clock\ClockInterface;

beforeEach(function () {
    Redis::flushdb();
    $this->p = person(OrgRole::Member, ProjectRole::Owner);
});

function revoke_row(Credential $credential): void
{
    Tenancy::crossTenant('inspect', fn () => Credential::query()->whereKey($credential->id)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'manual']));
}

describe('resolving a secret', function () {
    it('returns the credential without its secret, from MySQL and then from the cache', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p, ['scopes' => ['snapshot:read', 'events:write']]);
        $resolver = app(CredentialResolver::class);

        $first = $resolver->resolve($secret);

        // Delete the row: only the cache can still answer.
        Tenancy::crossTenant('inspect', fn () => DB::table('credentials')->delete());
        $second = $resolver->resolve($secret);

        expect($first->id)->toBe($credential->id)
            ->and($first->type)->toBe(CredentialType::Server)
            ->and($first->organizationId)->toBe($this->p['organization']->id)
            ->and($first->environmentId)->toBe($this->p['environment']->id)
            ->and($first->scopes)->toBe(['snapshot:read', 'events:write'])
            ->and($second->id)->toBe($credential->id)
            ->and(json_encode($first->toArray()))->not->toContain($secret);
    });

    it('answers a malformed, mistyped or unknown secret the same way', function (callable $secret) {
        $other = make_credential($this->p);

        expect(fn () => app(CredentialResolver::class)->resolve($secret($other['secret'])))->toThrow(InvalidCredential::class);
    })->with([
        'garbage' => [fn () => 'nope'],
        'bad checksum' => [fn (string $s) => substr($s, 0, -1).($s[-1] === 'a' ? 'b' : 'a')],
        'well formed but unknown' => [fn () => (new TokenGenerator)->generate(CredentialType::Server, 'dev')->secret],
    ]);

    it('remembers an unknown secret briefly without a second query', function () {
        $secret = (new TokenGenerator)->generate(CredentialType::Server, 'dev')->secret;
        $resolver = app(CredentialResolver::class);

        expect(fn () => $resolver->resolve($secret))->toThrow(InvalidCredential::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        expect(fn () => $resolver->resolve($secret))->toThrow(InvalidCredential::class)
            ->and(DB::getQueryLog())->toBe([]);
    });

    it('reports revoked, expired, rotated-out and expired-by-status credentials as revoked', function (array $change) {
        ['secret' => $secret] = make_credential($this->p, $change);

        expect(fn () => app(CredentialResolver::class)->resolve($secret))->toThrow(RevokedCredential::class);
    })->with([
        'revoked' => [['status' => CredentialStatus::Revoked]],
        'status expired' => [['status' => CredentialStatus::Expired]],
        'past its expiry' => [['expires_at' => '2020-01-01 00:00:00']],
        'rotating past the overlap' => [['status' => CredentialStatus::Rotating, 'overlap_ends_at' => '2020-01-01 00:00:00']],
    ]);

    it('rejects an expired credential at its expiry time even from a warm cache', function () {
        ['secret' => $secret] = make_credential($this->p, ['expires_at' => now()->addMinutes(10)]);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($secret);

        $this->travel(11)->minutes();

        expect(fn () => $resolver->resolve($secret))->toThrow(RevokedCredential::class);
    });

    it('lets a rotating credential work until the second its overlap ends', function () {
        ['secret' => $secret] = make_credential($this->p, ['status' => CredentialStatus::Rotating, 'overlap_ends_at' => now()->addHour()]);
        $resolver = app(CredentialResolver::class);

        $this->travel(59)->minutes();
        $this->travel(59)->seconds();
        expect($resolver->resolve($secret)->status)->toBe(CredentialStatus::Rotating);

        $this->travel(1)->seconds();
        expect(fn () => $resolver->resolve($secret))->toThrow(RevokedCredential::class);
    });

    it('finds a secret hashed under the previous pepper', function () {
        $secret = (new TokenGenerator)->generate(CredentialType::Server, 'dev');
        $old = new SecretHasher([1 => base64_encode(str_repeat('a', 32)), 2 => base64_encode(str_repeat('b', 32))], 1);
        make_credential($this->p, ['secret_hash' => $old->hash($secret->secret), 'pepper_id' => 1]);

        $rotated = new SecretHasher([1 => base64_encode(str_repeat('a', 32)), 2 => base64_encode(str_repeat('b', 32))], 2, 1);
        $resolver = new CredentialResolver($rotated, new CredentialCache, app(ClockInterface::class));

        expect($resolver->resolve($secret->secret)->type)->toBe(CredentialType::Server);
    });

    it('falls back to MySQL when Redis is unavailable', function () {
        ['secret' => $secret] = make_credential($this->p);
        config(['database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
        Redis::purge();

        expect(app(CredentialResolver::class)->resolve($secret)->type)->toBe(CredentialType::Server);
    });
});

describe('revocation and the cache', function () {
    it('is rejected on the next request when the state change overwrites the cache', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($secret);

        revoke_row($credential);
        app(CredentialCache::class)->overwrite($credential->secret_hash, CredentialSummaries::of(credential_now($credential)));

        expect(fn () => $resolver->resolve($secret))->toThrow(RevokedCredential::class);
    });

    it('is not undone by a request that read MySQL before the revocation and fills the cache after it', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p);
        $cache = app(CredentialCache::class);

        // A resolver read the active row, then paused.
        $stale = CredentialSummaries::of(credential_now($credential));

        revoke_row($credential);
        $cache->overwrite($credential->secret_hash, CredentialSummaries::of(credential_now($credential)));

        // It wakes up and tries to fill: the entry exists, so the fill does nothing.
        expect($cache->fill($credential->secret_hash, $stale))->toBeFalse()
            ->and(fn () => app(CredentialResolver::class)->resolve($secret))->toThrow(RevokedCredential::class);
    });

    it('is overwritten by the state change when the stale fill came first', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p);
        $cache = app(CredentialCache::class);
        $cache->fill($credential->secret_hash, CredentialSummaries::of(credential_now($credential)));

        revoke_row($credential);
        $cache->overwrite($credential->secret_hash, CredentialSummaries::of(credential_now($credential)));

        expect(fn () => app(CredentialResolver::class)->resolve($secret))->toThrow(RevokedCredential::class);
    });

    it('is repaired by the outbox consumer when the inline cache write never happened', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($secret);

        // The revocation committed, then the process died before it wrote the cache.
        revoke_row($credential);
        expect($resolver->resolve($secret)->status)->toBe(CredentialStatus::Active);

        app(RepairCredentialCache::class)->consume(OutboxMessage::forOrganization($this->p['organization']->id, 'internal.credential.state_changed', 'credential', $credential->id, ['credential_id' => $credential->id]));

        expect(fn () => $resolver->resolve($secret))->toThrow(RevokedCredential::class);
    });

    it('is rejected within the cache time to live even if nothing repairs the entry', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($secret);

        revoke_row($credential);
        // Real Redis expiry: shorten the entry to the fill time to live and let it lapse.
        Redis::expire('bf:cred:'.bin2hex($credential->secret_hash), 1);
        sleep(2);

        expect(fn () => $resolver->resolve($secret))->toThrow(RevokedCredential::class)
            ->and(CredentialCache::TTL_SECONDS)->toBe(60);
    });

    it('is rejected when Redis was unavailable during the revocation and returns afterwards', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p);
        $resolver = app(CredentialResolver::class);
        $resolver->resolve($secret);

        revoke_row($credential);
        // The inline overwrite failed (Redis was down); when it returns the outbox consumer writes the state.
        app(RepairCredentialCache::class)->consume(OutboxMessage::forOrganization($this->p['organization']->id, 'internal.credential.state_changed', 'credential', $credential->id, ['credential_id' => $credential->id]));

        expect(fn () => $resolver->resolve($secret))->toThrow(RevokedCredential::class);
    });
});

describe('last used', function () {
    it('makes no database write for authenticated requests and one on flush', function () {
        ['secret' => $secret, 'credential' => $credential] = make_credential($this->p);
        $resolver = app(CredentialResolver::class);
        $recorder = app(CredentialUsageRecorder::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        for ($i = 0; $i < 100; $i++) {
            $resolved = $resolver->resolve($secret);
            $recorder->handle($resolved->organizationId, $resolved->id, 'bitflag-cli/1.8.2');
        }

        $writes = array_filter(DB::getQueryLog(), fn (array $query) => preg_match('/^\s*(update|insert|delete)/i', $query['query']) === 1);
        expect($writes)->toBe([])
            ->and(credential_now($credential)->last_used_at)->toBeNull();

        expect(app(FlushCredentialLastUsed::class)->handle())->toBe(1);

        $after = credential_now($credential);
        expect($after->last_used_at)->not->toBeNull()
            ->and($after->last_used_client)->toBe('bitflag-cli/1.8.2')
            ->and(app(FlushCredentialLastUsed::class)->handle())->toBe(0);
    });

    it('records a credential at most once a minute and never moves a time backwards', function () {
        ['credential' => $credential] = make_credential($this->p, ['last_used_at' => now()->addDay()]);
        $recorder = app(CredentialUsageRecorder::class);

        $recorder->handle($this->p['organization']->id, $credential->id);
        $recorder->handle($this->p['organization']->id, $credential->id);

        expect(Redis::zcard(CredentialUsageRecorder::LAST_USED_KEY))->toBe(1);
        app(FlushCredentialLastUsed::class)->handle();

        expect(credential_now($credential)->last_used_at->isFuture())->toBeTrue();
    });
});

it('isolates credentials by organization', function () {
    assertTenantIsolated(Credential::class, function (Organization $organization) {
        $environment = Environment::factory()->create([
            'project_id' => Project::factory()->create(['organization_id' => $organization->id])->id,
            'organization_id' => $organization->id,
        ]);

        return Credential::factory()->create(['environment_id' => $environment->id]);
    });
});
