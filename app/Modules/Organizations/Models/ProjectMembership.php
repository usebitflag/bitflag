<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Database\Factories\ProjectMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's role in one project: owner, publisher, developer or viewer.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $user_id
 * @property string $role
 */
#[UseFactory(ProjectMembershipFactory::class)]
class ProjectMembership extends Model
{
    /** @use HasFactory<ProjectMembershipFactory> */
    use BelongsToOrganization, HasFactory, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = ['organization_id', 'project_id', 'user_id', 'role'];
}
