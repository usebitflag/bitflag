<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Credentials\Support\MaskSecretsInLogs;
use App\Modules\Credentials\Support\RedactSecrets;
use App\Modules\Credentials\Support\RedisClient;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/**
 * The strongest promise of the credentials work: a secret exists only in the memory of the request that issues it and in that
 * request's response. After issuing every kind of credential, using each one, rotating and revoking, and forcing an exception in
 * a request that carries one, no secret may be found in any database column, Redis key or value, log file or session.
 */
beforeEach(function () {
    Cache::flush();
    Redis::flushdb();
    $this->logFile = sys_get_temp_dir().'/bitflag-leak-'.bin2hex(random_bytes(4)).'.log';
    config([
        'logging.default' => 'leak',
        'logging.channels.leak' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug', 'tap' => [MaskSecretsInLogs::class]],
    ]);
    Log::forgetChannel('leak');
    $this->world = portalWorld();
    Tenancy::crossTenant('inspect', function () {
        foreach (Environment::query()->get() as $environment) {
            $policy = EnvironmentPolicy::query()->create(['organization_id' => $environment->organization_id, 'project_id' => $environment->project_id, 'environment_id' => $environment->id, 'version' => 1]);
            $environment->forceFill(['current_policy_id' => $policy->id])->save();
        }
    });
});

afterEach(fn () => @unlink($this->logFile));

/**
 * Every text, JSON and binary column of every table, searched for each needle.
 *
 * @param  list<string>  $needles
 * @return list<string> "table.column" for each hit
 */
function database_hits(array $needles): array
{
    $schema = DB::selectOne('select database() as name')->name;
    $columns = DB::select("select table_name as t, column_name as c from information_schema.columns where table_schema = ? and data_type in ('char','varchar','tinytext','text','mediumtext','longtext','json','tinyblob','blob','mediumblob','longblob','binary','varbinary')", [$schema]);
    $hits = [];

    foreach ($columns as $column) {
        foreach ($needles as $needle) {
            if (DB::selectOne("select count(*) as n from `{$column->t}` where instr(`{$column->c}`, ?) > 0", [$needle])->n > 0) {
                $hits[] = "{$column->t}.{$column->c}";
            }
        }
    }

    return array_values(array_unique($hits));
}

/**
 * Every key and value in the test Redis database, as one string.
 */
function redis_dump(): string
{
    $client = RedisClient::get();
    $out = '';

    foreach ($client->keys('*') ?: [] as $key) {
        $out .= $key."\n";
        $out .= match ($client->type($key)) {
            \Redis::REDIS_STRING => (string) $client->get($key),
            \Redis::REDIS_HASH => json_encode($client->hGetAll($key)),
            \Redis::REDIS_ZSET => json_encode($client->zRange($key, 0, -1, true)),
            \Redis::REDIS_LIST => json_encode($client->lRange($key, 0, -1)),
            \Redis::REDIS_SET => json_encode($client->sMembers($key)),
            default => '',
        };
        $out .= "\n";
    }

    return $out;
}

it('finds no credential secret anywhere after a credential\'s whole life', function () {
    $base = '/acme/storefront/production/settings';
    $secrets = [];

    // Issue every kind.
    foreach ([['server', ['snapshot:read', 'evaluate']], ['browser', ['snapshot:read']], ['management', ['flags:read', 'publish']]] as [$type, $scopes]) {
        $response = $this->actingAs($this->world['maya'])->postJson("{$base}/environments/staging/keys", ['type' => $type, 'label' => "{$type} key", 'scopes' => $scopes, 'ttl_days' => null])->assertCreated();
        $secrets[$type] = ['secret' => $response->json('secret'), 'id' => $response->json('credential.id')];
    }
    $secrets['personal'] = ['secret' => $this->actingAs($this->world['maya'])->postJson("{$base}/tokens", ['label' => 'Laptop', 'access' => 'draft', 'ttl_days' => 90, 'password' => 'password'])->assertCreated()->json('secret')];

    // Use each one.
    foreach (['management', 'personal'] as $type) {
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$secrets[$type]['secret']])->assertOk();
    }
    foreach (['server', 'browser'] as $type) {
        app(CredentialResolver::class)->resolve($secrets[$type]['secret']);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$secrets[$type]['secret']])->assertUnauthorized();
    }
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer bf_srv_stg_'.str_repeat('x', 36)])->assertUnauthorized();

    // Rotate one and revoke another.
    $rotated = $this->actingAs($this->world['maya'])->postJson("{$base}/environments/staging/keys/{$secrets['server']['id']}/rotate", ['overlap' => 3600])->assertCreated();
    $secrets['rotated'] = ['secret' => $rotated->json('secret')];
    $this->actingAs($this->world['maya'])->post("{$base}/environments/staging/keys/{$secrets['browser']['id']}/revoke")->assertRedirect();
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$secrets['browser']['secret']])->assertUnauthorized();

    // Force an exception, in a request that carries a secret, whose message holds the secret.
    Route::middleware('auth.token')->get('/api/v1/__boom', fn () => throw new RuntimeException('boom with '.request()->bearerToken()));
    $boom = $this->getJson('/api/v1/__boom', ['Authorization' => 'Bearer '.$secrets['management']['secret']]);
    $boom->assertStatus(500);
    expect($boom->getContent())->not->toContain($secrets['management']['secret']);

    // Search everything, for the full secret and for its random part.
    $needles = [];
    foreach ($secrets as $entry) {
        $needles[] = $entry['secret'];
        $needles[] = TokenGenerator::randomPart($entry['secret']);
    }

    $logs = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    $redis = redis_dump();
    $session = (string) json_encode(app('session.store')->all());

    expect(Tenancy::crossTenant('the leak test reads every table', fn () => database_hits($needles)))->toBe([]);

    foreach ($needles as $needle) {
        expect($logs)->not->toContain($needle)
            ->and($redis)->not->toContain($needle)
            ->and($session)->not->toContain($needle);
    }

    // The log did record the failure, with the secret masked.
    expect($logs)->toContain('boom with')->toContain(RedactSecrets::MASK);
});
