<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Modules\Organizations\Domain\InvitationStatus;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An offer to join an organization, with the projects it adds the person to. The link carries a random token; only its
 * peppered hash is stored, and a new token replaces it on every resend.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property OrgRole $org_role
 * @property InvitationStatus $status
 * @property string|null $token_hash
 * @property string $invited_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $expires_at
 * @property string|null $accepted_by
 * @property Carbon|null $accepted_at
 * @property string|null $revoked_by
 * @property Carbon|null $revoked_at
 */
class Invitation extends Model
{
    use BelongsToOrganization, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = [
        'organization_id', 'email', 'org_role', 'status', 'token_hash', 'invited_by', 'sent_at', 'expires_at',
        'accepted_by', 'accepted_at', 'revoked_by', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'org_role' => OrgRole::class,
            'status' => InvitationStatus::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InvitationProjectGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(InvitationProjectGrant::class);
    }
}
