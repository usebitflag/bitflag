<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser that skips the second-factor prompt until it expires or is revoked. The cookie holds a random token; only
 * its peppered hash is stored.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property string $device_label
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class TrustedBrowser extends Model
{
    use HasUlids, UsesMicrosecondTimestamps;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'token_hash', 'device_label', 'expires_at', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
