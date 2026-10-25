<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Domain\MfaFactorType;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's second factor. A row without `confirmed_at` is an enrollment that has not been confirmed with a code yet.
 *
 * @property string $id
 * @property string $user_id
 * @property MfaFactorType $type
 * @property string $secret
 * @property Carbon|null $confirmed_at
 * @property int|null $last_used_timestep
 * @property Carbon|null $last_used_at
 */
class MfaFactor extends Model
{
    use HasUlids, UsesMicrosecondTimestamps;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'secret', 'confirmed_at', 'last_used_timestep', 'last_used_at'];

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MfaFactorType::class,
            'secret' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_used_timestep' => 'integer',
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
