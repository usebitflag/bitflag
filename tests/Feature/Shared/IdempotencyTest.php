<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Shared\Idempotency\Idempotency;
use App\Shared\Idempotency\IdempotencyKeyReused;
use App\Shared\Idempotency\IdempotencyOutcome;
use App\Shared\Idempotency\IdempotencyRecord;
use App\Shared\Idempotency\IdempotencyScope;
use App\Shared\Idempotency\IdempotencyStatus;
use App\Shared\Idempotency\OperationResult;
use App\Shared\Idempotency\RequestFingerprint;
use App\Shared\Persistence\TransactionGuard;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 10:00:00');
    $this->organization = Organization::factory()->create();
    $this->scope = new IdempotencyScope($this->organization->id, 'usr_01j9x3k7q2m5n8p0r4s6t8v0wy', 'publish_environment');
    $this->runs = 0;
    $this->operation = function (array $body = ['revision' => 42], array $secrets = []): Closure {
        return function () use ($body, $secrets): OperationResult {
            $this->runs++;

            return new OperationResult(201, $body, 'revision', '01j9x3k7q2m5n8p0r4s6t8v0wz', $secrets);
        };
    };
});

afterEach(fn () => Carbon::setTestNow());

function idempotently(string $key, string $fingerprint, Closure $operation, ?IdempotencyScope $scope = null): IdempotencyOutcome
{
    return DB::transaction(fn () => app(Idempotency::class)->run($scope ?? test()->scope, $key, $fingerprint, $operation));
}

it('runs the operation the first time and stores the result', function () {
    $outcome = idempotently('key-1', RequestFingerprint::of(['a' => 1]), ($this->operation)());

    expect($outcome->replayed)->toBeFalse()
        ->and($outcome->status())->toBe(201)
        ->and($outcome->body())->toBe(['revision' => 42])
        ->and($this->runs)->toBe(1);

    $record = Tenancy::crossTenant('inspect', fn () => IdempotencyRecord::query()->firstOrFail());

    expect($record->status)->toBe(IdempotencyStatus::Completed)
        ->and($record->response_status)->toBe(201)
        ->and($record->result_type)->toBe('revision')
        ->and($record->expires_at?->format('Y-m-d H:i:s'))->toBe('2026-10-20 10:00:00');
});

it('replays the stored result for the same key and request without running again', function () {
    $fingerprint = RequestFingerprint::of(['a' => 1]);
    idempotently('key-1', $fingerprint, ($this->operation)());

    $again = idempotently('key-1', $fingerprint, ($this->operation)(['revision' => 99]));

    expect($again->replayed)->toBeTrue()
        ->and($again->body())->toBe(['revision' => 42])
        ->and($this->runs)->toBe(1);
});

it('rejects the same key with a different request', function () {
    idempotently('key-1', RequestFingerprint::of(['a' => 1]), ($this->operation)());

    idempotently('key-1', RequestFingerprint::of(['a' => 2]), ($this->operation)());
})->throws(IdempotencyKeyReused::class);

it('renders a reused key as a 409 error', function () {
    idempotently('key-1', RequestFingerprint::of(['a' => 1]), ($this->operation)());
    Route::middleware('api')->prefix('api/v1')->get('/_t/reused', fn () => throw new IdempotencyKeyReused);

    $this->getJson('/api/v1/_t/reused')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
});

it('keys are unique per organization, actor and operation', function () {
    $fingerprint = RequestFingerprint::of(['a' => 1]);
    idempotently('key-1', $fingerprint, ($this->operation)());

    $otherOperation = new IdempotencyScope($this->organization->id, $this->scope->actorKey, 'rollback_environment');
    $otherActor = new IdempotencyScope($this->organization->id, 'usr_01j9x3k7q2m5n8p0r4s6t8v0wa', 'publish_environment');

    idempotently('key-1', $fingerprint, ($this->operation)(), $otherOperation);
    idempotently('key-1', $fingerprint, ($this->operation)(), $otherActor);

    expect($this->runs)->toBe(3);
});

it('does not replay secrets, and says so', function () {
    $fingerprint = RequestFingerprint::of(['issue' => 'key']);

    $first = idempotently('issue-1', $fingerprint, ($this->operation)(['key_id' => 'key_1'], ['secret' => 'bf_srv_plaintext']));
    $replay = idempotently('issue-1', $fingerprint, ($this->operation)());

    expect($first->result->oneTimeSecrets)->toBe(['secret' => 'bf_srv_plaintext'])
        ->and($replay->replayed)->toBeTrue()
        ->and($replay->body())->toBe(['key_id' => 'key_1', 'details' => ['secret_not_replayable' => true]]);

    $stored = Tenancy::crossTenant('inspect', fn () => (string) json_encode(IdempotencyRecord::query()->firstOrFail()->response_body));
    expect($stored)->not->toContain('bf_srv_plaintext');
});

it('leaves no reservation behind when the operation fails', function () {
    try {
        idempotently('key-1', RequestFingerprint::of(['a' => 1]), fn () => throw new RuntimeException('failed'));
    } catch (RuntimeException) {
        // expected
    }

    expect(Tenancy::crossTenant('inspect', fn () => IdempotencyRecord::query()->count()))->toBe(0);

    $retry = idempotently('key-1', RequestFingerprint::of(['a' => 1]), ($this->operation)());
    expect($retry->replayed)->toBeFalse();
});

it('treats an expired record as a new request', function () {
    $fingerprint = RequestFingerprint::of(['a' => 1]);
    idempotently('key-1', $fingerprint, ($this->operation)());

    Carbon::setTestNow('2026-10-20 10:00:01');
    $again = idempotently('key-1', $fingerprint, ($this->operation)(['revision' => 43]));

    expect($again->replayed)->toBeFalse()->and($again->body())->toBe(['revision' => 43])->and($this->runs)->toBe(2);
});

it('prunes expired records and keeps live ones', function () {
    idempotently('old', RequestFingerprint::of(['a' => 1]), ($this->operation)());
    Carbon::setTestNow('2026-10-20 09:00:00');
    idempotently('new', RequestFingerprint::of(['a' => 1]), ($this->operation)());

    Carbon::setTestNow('2026-10-20 10:30:00');
    $this->artisan('idempotency:prune')->assertSuccessful();

    $keys = Tenancy::crossTenant('inspect', fn () => IdempotencyRecord::query()->pluck('idempotency_key')->all());
    expect($keys)->toBe(['new']);
});

it('refuses to run outside a transaction', function () {
    $plain = DB::connectUsing('plain', config('database.connections.mysql'));
    $idempotency = app()->make(Idempotency::class, ['transactions' => new TransactionGuard($plain)]);

    $idempotency->run($this->scope, 'key-1', 'f', ($this->operation)());
})->throws(LogicException::class);

it('gives the same fingerprint however the keys are ordered', function () {
    expect(RequestFingerprint::of(['b' => [1, 2], 'a' => ['y' => 1, 'x' => 2]]))
        ->toBe(RequestFingerprint::of(['a' => ['x' => 2, 'y' => 1], 'b' => [1, 2]]))
        ->not->toBe(RequestFingerprint::of(['a' => ['x' => 2, 'y' => 1], 'b' => [2, 1]]));
});

it('keeps organizations apart', function () {
    app(TenantContext::class)->clear();

    assertTenantIsolated(IdempotencyRecord::class, function (Organization $organization) {
        $scope = new IdempotencyScope($organization->id, 'usr_x', 'op');
        DB::transaction(fn () => app(Idempotency::class)->run($scope, 'k', 'f', fn () => new OperationResult(200, [])));

        return Tenancy::crossTenant('fetch', fn () => IdempotencyRecord::query()->where('organization_id', $organization->id)->firstOrFail());
    });
});
