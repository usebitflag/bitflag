<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device a person has signed in from, so the first sign-in from an unseen one can be announced by email.
 *
 * @property string $id
 * @property string $user_id
 * @property string $device_hash
 * @property string $device_label
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
class KnownDevice extends Model
{
    use HasUlids, UsesMicrosecondTimestamps;

    public $timestamps = false;

    protected $fillable = ['user_id', 'device_hash', 'device_label', 'first_seen_at', 'last_seen_at'];

    protected $hidden = ['device_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
