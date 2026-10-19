<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored outbox message. The table holds organization, account and system events, so it is not tenant-scoped by a
 * global scope: the dispatcher reads it across organizations, and every other reader filters by organization_id or by
 * scope and user_id.
 *
 * @property string $id
 * @property OutboxScope $scope
 * @property string|null $organization_id
 * @property string|null $user_id
 * @property string|null $project_id
 * @property string|null $environment_id
 * @property string $type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<array-key, mixed> $payload
 * @property string|null $request_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $dispatched_at
 * @property int $attempts
 */
final class OutboxMessageRecord extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'outbox_messages';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id', 'scope', 'organization_id', 'user_id', 'project_id', 'environment_id', 'type', 'aggregate_type',
        'aggregate_id', 'payload', 'request_id', 'occurred_at', 'available_at',
    ];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    public function toMessage(): OutboxMessage
    {
        return OutboxMessage::restore(
            $this->id,
            $this->scope,
            $this->organization_id,
            $this->user_id,
            $this->project_id,
            $this->environment_id,
            $this->type,
            $this->aggregate_type,
            $this->aggregate_id,
            $this->payload,
            $this->request_id,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => OutboxScope::class,
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }
}
