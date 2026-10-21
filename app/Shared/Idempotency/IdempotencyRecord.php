<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The reservation and stored result of one idempotent request.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $actor_key
 * @property string $operation
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property IdempotencyStatus $status
 * @property int|null $response_status
 * @property array{body: array<array-key, mixed>, had_secret: bool}|null $response_body
 * @property string|null $result_type
 * @property string|null $result_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 */
final class IdempotencyRecord extends Model
{
    use BelongsToOrganization, HasUlids, UsesMicrosecondTimestamps;

    public $timestamps = false;

    protected $table = 'idempotency_records';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'actor_key', 'operation', 'idempotency_key', 'request_fingerprint', 'status',
        'response_status', 'response_body', 'result_type', 'result_id', 'created_at', 'completed_at', 'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_body' => 'array',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
