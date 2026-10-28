<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Shared\Capabilities\ProjectRole;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One project an invitation adds the person to, and the role they get there.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $invitation_id
 * @property ProjectRole $role
 */
class InvitationProjectGrant extends Model
{
    use BelongsToOrganization, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = ['organization_id', 'project_id', 'invitation_id', 'role'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => ProjectRole::class];
    }
}
