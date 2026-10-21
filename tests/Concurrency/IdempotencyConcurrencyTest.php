<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Shared\Idempotency\Idempotency;
use App\Shared\Idempotency\IdempotencyInProgress;
use App\Shared\Idempotency\IdempotencyScope;
use App\Shared\Idempotency\OperationResult;
use App\Shared\Persistence\TransactionGuard;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

// Two connections stand in for two requests that carry the same idempotency key at the same moment.

function connectionNamed(string $name): Connection
{
    $connection = DB::getConnections()[$name] ?? DB::connectUsing($name, config('database.connections.mysql'));
    assert($connection instanceof Connection);

    return $connection;
}

function idempotencyOn(Connection $connection): Idempotency
{
    return new Idempotency(new TransactionGuard($connection), app(ClockInterface::class), app(TenantContext::class), $connection);
}

it('makes a duplicate wait for the first request, then answers IDEMPOTENCY_IN_PROGRESS', function () {
    config(['idempotency.lock_wait_seconds' => 1]);
    $organization = Organization::factory()->create();
    $scope = new IdempotencyScope($organization->id, 'usr_x', 'publish_environment');
    $first = connectionNamed('request_first');
    $second = connectionNamed('request_second');
    $operation = fn () => new OperationResult(201, ['revision' => 42]);

    // The first request has reserved the key and is still working.
    $first->beginTransaction();
    idempotencyOn($first)->run($scope, 'key-1', 'fingerprint', $operation);

    $second->beginTransaction();
    expect(fn () => idempotencyOn($second)->run($scope, 'key-1', 'fingerprint', $operation))->toThrow(IdempotencyInProgress::class);
    $second->rollBack();

    // Once the first request commits, the same request gets its result back.
    $first->commit();
    $second->beginTransaction();
    $replay = idempotencyOn($second)->run($scope, 'key-1', 'fingerprint', $operation);
    $second->commit();

    expect($replay->replayed)->toBeTrue()->and($replay->body())->toBe(['revision' => 42]);
});

it('sends Retry-After with the in-progress answer', function () {
    expect((new IdempotencyInProgress)->headers())->toBe(['Retry-After' => '1'])
        ->and((new IdempotencyInProgress)->httpStatus())->toBe(409)
        ->and((new IdempotencyInProgress)->errorCode())->toBe('IDEMPOTENCY_IN_PROGRESS');
});
