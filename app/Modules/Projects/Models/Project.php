<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $key
 * @property string|null $description
 * @property string $status
 */
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToOrganization, HasFactory, HasPublicId, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = ['organization_id', 'name', 'key', 'description', 'status'];

    public static function publicIdPrefix(): IdPrefix
    {
        return IdPrefix::Project;
    }
}
