<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's role in an organization: owner or member.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $role
 */
#[UseFactory(OrganizationMembershipFactory::class)]
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use BelongsToOrganization, HasFactory, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = ['organization_id', 'user_id', 'role'];
}
