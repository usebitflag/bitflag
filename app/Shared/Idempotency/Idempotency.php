<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Persistence\TransactionGuard;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Makes a retryable operation safe to repeat. Call it inside the action's transaction:
 *
 *   DB::transaction(fn () => $idempotency->run($scope, $key, RequestFingerprint::of($request), function () { … }));
 *
 * - First request: reserves the key with a unique-index insert, runs the operation, and stores the result in the same
 *   commit, so a rolled-back operation leaves no reservation behind.
 * - Same key and same request after completion: returns the stored result without running the operation again.
 * - Same key with a different request: IDEMPOTENCY_KEY_REUSED.
 * - A duplicate that arrives while the first request still holds the key waits a few seconds for it, then gets
 *   IDEMPOTENCY_IN_PROGRESS with a Retry-After.
 *
 * Stored results never contain one-time secrets. Replaying an operation that issued one returns the metadata with
 * details.secret_not_replayable set.
 */
final class Idempotency
{
    private const MYSQL_DUPLICATE_KEY = 1062;

    private const MYSQL_LOCK_WAIT_TIMEOUT = 1205;

    public function __construct(
        private readonly TransactionGuard $transactions,
        private readonly ClockInterface $clock,
        private readonly TenantContext $tenant,
        private readonly ?Connection $connection = null,
    ) {}

    /**
     * @param  Closure(): OperationResult  $operation
     */
    public function run(IdempotencyScope $scope, string $key, string $fingerprint, Closure $operation): IdempotencyOutcome
    {
        $this->transactions->assertInTransaction('An idempotency record');

        return $this->tenant->run($scope->organizationId, function () use ($scope, $key, $fingerprint, $operation): IdempotencyOutcome {
            $existing = $this->find($scope, $key);

            if ($existing !== null && $this->isExpired($existing)) {
                $existing->delete();
                $existing = null;
            }

            if ($existing !== null) {
                return $this->replay($existing, $fingerprint);
            }

            try {
                $record = $this->reserve($scope, $key, $fingerprint);
            } catch (QueryException $e) {
                $existing = match ($this->mysqlCode($e)) {
                    self::MYSQL_DUPLICATE_KEY => $this->find($scope, $key),
                    self::MYSQL_LOCK_WAIT_TIMEOUT => throw new IdempotencyInProgress,
                    default => throw $e,
                };

                if ($existing === null) {
                    throw $e;
                }

                return $this->replay($existing, $fingerprint);
            }

            $result = $operation();

            $record->forceFill([
                'status' => IdempotencyStatus::Completed,
                'response_status' => $result->status,
                'response_body' => ['body' => $result->body, 'had_secret' => $result->hasOneTimeSecrets()],
                'result_type' => $result->resultType,
                'result_id' => $result->resultId,
                'completed_at' => $this->clock->now(),
            ])->save();

            return new IdempotencyOutcome($result, replayed: false);
        });
    }

    private function find(IdempotencyScope $scope, string $key): ?IdempotencyRecord
    {
        return $this->records()
            ->where('organization_id', $scope->organizationId)
            ->where('actor_key', $scope->actorKey)
            ->where('operation', $scope->operation)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function reserve(IdempotencyScope $scope, string $key, string $fingerprint): IdempotencyRecord
    {
        $connection = $this->connection ?? DB::connection();
        $wait = max(1, Config::integer('idempotency.lock_wait_seconds', 3));
        $previous = $connection->selectOne('select @@session.innodb_lock_wait_timeout as seconds');
        $connection->statement("set session innodb_lock_wait_timeout = {$wait}");

        try {
            $now = $this->clock->now();

            return $this->records()->create([
                'organization_id' => $scope->organizationId,
                'actor_key' => $scope->actorKey,
                'operation' => $scope->operation,
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'status' => IdempotencyStatus::InProgress,
                'created_at' => $now,
                'expires_at' => $now->modify('+'.Config::integer('idempotency.ttl_hours', 24).' hours'),
            ]);
        } finally {
            $seconds = is_object($previous) ? ($previous->seconds ?? null) : null;
            $restore = is_int($seconds) ? $seconds : (is_string($seconds) && ctype_digit($seconds) ? (int) $seconds : 50);
            $connection->statement("set session innodb_lock_wait_timeout = {$restore}");
        }
    }

    /**
     * @return Builder<IdempotencyRecord>
     */
    private function records(): Builder
    {
        return IdempotencyRecord::on(($this->connection ?? DB::connection())->getName());
    }

    private function replay(IdempotencyRecord $record, string $fingerprint): IdempotencyOutcome
    {
        if (! hash_equals($record->request_fingerprint, $fingerprint)) {
            throw new IdempotencyKeyReused;
        }

        if ($record->status !== IdempotencyStatus::Completed || $record->response_body === null) {
            throw new IdempotencyInProgress;
        }

        $result = new OperationResult(
            $record->response_status ?? 200,
            $record->response_body['body'],
            $record->result_type,
            $record->result_id,
        );

        return new IdempotencyOutcome($result, replayed: true, secretNotReplayable: $record->response_body['had_secret']);
    }

    private function isExpired(IdempotencyRecord $record): bool
    {
        return $record->expires_at !== null && $record->expires_at <= $this->clock->now();
    }

    private function mysqlCode(QueryException $e): ?int
    {
        $code = $e->errorInfo[1] ?? null;

        return is_int($code) ? $code : null;
    }
}
