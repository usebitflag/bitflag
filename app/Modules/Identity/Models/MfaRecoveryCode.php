<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One single-use recovery code, stored only as a peppered hash. The code itself is shown once, when it is generated.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
class MfaRecoveryCode extends Model
{
    use HasUlids, UsesMicrosecondTimestamps;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
