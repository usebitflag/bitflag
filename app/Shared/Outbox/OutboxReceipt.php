<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One consumer's outstanding work for one message. The receipt row is the authoritative record of that work: queue jobs
 * only make it start quickly, and recovery rebuilds them from receipts.
 *
 * @property string $id
 * @property string $outbox_message_id
 * @property string $scope
 * @property string|null $organization_id
 * @property string|null $user_id
 * @property string $consumer
 * @property ReceiptStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $lease_owner
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $last_signalled_at
 * @property string|null $last_error
 */
final class OutboxReceipt extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'outbox_receipts';

    protected $keyType = 'string';

    protected $guarded = [];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'next_attempt_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'last_signalled_at' => 'immutable_datetime',
        ];
    }
}
