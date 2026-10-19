<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One entry in an organization's history: who did what to which object, where and why. Entries are written by
 * AuditRecorder and never changed; the model refuses updates and deletes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $project_id
 * @property string|null $environment_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $actor_display
 * @property string|null $via_token_id
 * @property string|null $token_creator_id
 * @property string $channel
 * @property string $action
 * @property string $target_type
 * @property string|null $target_id
 * @property string|null $target_display
 * @property int|null $from_revision
 * @property int|null $to_revision
 * @property string|null $reason
 * @property string|null $request_id
 * @property string|null $correlation_id
 * @property array<array-key, mixed>|null $details
 * @property CarbonImmutable $occurred_at
 */
final class AuditEvent extends Model
{
    use BelongsToOrganization, HasPublicId, HasUlids, UsesMicrosecondTimestamps;

    public $timestamps = false;

    protected $table = 'audit_events';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'project_id', 'environment_id', 'actor_type', 'actor_id', 'actor_display', 'via_token_id',
        'token_creator_id', 'channel', 'action', 'target_type', 'target_id', 'target_display', 'from_revision',
        'to_revision', 'reason', 'request_id', 'correlation_id', 'details', 'occurred_at',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw AuditEventImmutable::cannot('update');
        });

        self::deleting(static function (): never {
            throw AuditEventImmutable::cannot('delete');
        });
    }

    public static function publicIdPrefix(): IdPrefix
    {
        return IdPrefix::AuditEntry;
    }

    /**
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): AuditEventBuilder
    {
        return new AuditEventBuilder($query);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'immutable_datetime',
            'from_revision' => 'integer',
            'to_revision' => 'integer',
        ];
    }
}
