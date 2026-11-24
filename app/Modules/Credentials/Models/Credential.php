<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Models;

use App\Modules\Credentials\Domain\CredentialAudience;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\RevokeReason;
use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A key or token. Only the peppered hash of its secret is stored.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $environment_id
 * @property string|null $user_id
 * @property CredentialType $type
 * @property string $label
 * @property string $display_prefix
 * @property string $last4
 * @property string $secret_hash
 * @property int $pepper_id
 * @property list<string> $scopes
 * @property int|null $ttl_days
 * @property CarbonImmutable|null $expires_at
 * @property CredentialStatus $status
 * @property string|null $rotated_from_id
 * @property string|null $replaced_by_id
 * @property CarbonImmutable|null $overlap_ends_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property RevokeReason|null $revoke_reason
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_used_client
 * @property CredentialAudience $audience
 * @property string|null $created_by
 */
#[UseFactory(CredentialFactory::class)]
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use BelongsToOrganization, HasFactory, HasPublicId, HasUlids, UsesMicrosecondTimestamps;

    /** @var list<string> */
    protected $hidden = ['secret_hash'];

    protected $fillable = [
        'organization_id', 'project_id', 'environment_id', 'user_id', 'type', 'label', 'display_prefix', 'last4',
        'secret_hash', 'pepper_id', 'scopes', 'ttl_days', 'expires_at', 'status', 'rotated_from_id', 'replaced_by_id',
        'overlap_ends_at', 'revoked_at', 'revoked_by', 'revoke_reason', 'audience', 'created_by',
    ];

    public static function publicIdPrefix(): IdPrefix
    {
        return IdPrefix::Key;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'status' => CredentialStatus::class,
            'audience' => CredentialAudience::class,
            'revoke_reason' => RevokeReason::class,
            'scopes' => 'array',
            'pepper_id' => 'integer',
            'ttl_days' => 'integer',
            'expires_at' => 'immutable_datetime',
            'overlap_ends_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }
}
