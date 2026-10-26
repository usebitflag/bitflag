<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Data\SocialProvider;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's account at a provider, keyed on the provider's own user id.
 *
 * @property string $id
 * @property string $user_id
 * @property SocialProvider $provider
 * @property string $provider_user_id
 * @property string $email
 * @property Carbon|null $last_used_at
 */
class SocialIdentity extends Model
{
    use HasUlids, UsesMicrosecondTimestamps;

    public $timestamps = false;

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email', 'created_at', 'last_used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'created_at' => 'datetime',
            'last_used_at' => 'datetime',
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
