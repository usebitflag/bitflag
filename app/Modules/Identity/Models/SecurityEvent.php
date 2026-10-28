<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Domain\SecurityEventType;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the security log. An attempt against an address with no account has no user and keeps only a hash of the
 * address. Rows are kept 90 days.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string|null $email_hash
 * @property SecurityEventType $type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class SecurityEvent extends Model
{
    use HasUlids, UsesMicrosecondTimestamps;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email_hash', 'type', 'ip_address', 'user_agent', 'created_at'];

    protected $hidden = ['email_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => SecurityEventType::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
