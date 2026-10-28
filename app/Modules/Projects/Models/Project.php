<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Domain\FlagKeyFormat;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
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
 * @property string $time_zone
 * @property FlagKeyFormat $flag_key_format
 * @property int $stale_after_days
 * @property bool $require_flag_description
 * @property bool $require_flag_owner
 * @property bool $client_visible_by_default
 * @property ProjectStatus $status
 * @property CarbonImmutable|null $key_locked_at
 * @property int $version
 * @property string|null $created_by
 * @property ProjectStatus|null $status_before_deletion
 * @property CarbonImmutable|null $archived_at
 * @property string|null $archived_by
 * @property CarbonImmutable|null $deletion_scheduled_at
 * @property CarbonImmutable|null $deletion_due_at
 * @property string|null $deletion_scheduled_by
 * @property bool $deletion_scheduled_with_org
 * @property CarbonImmutable|null $deleted_at
 */
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToOrganization, HasFactory, HasPublicId, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = [
        'organization_id', 'name', 'key', 'description', 'time_zone', 'flag_key_format', 'stale_after_days',
        'require_flag_description', 'require_flag_owner', 'client_visible_by_default', 'status', 'created_by',
    ];

    public static function publicIdPrefix(): IdPrefix
    {
        return IdPrefix::Project;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'flag_key_format' => FlagKeyFormat::class,
            'stale_after_days' => 'integer',
            'require_flag_description' => 'boolean',
            'require_flag_owner' => 'boolean',
            'client_visible_by_default' => 'boolean',
            'key_locked_at' => 'immutable_datetime',
            'status_before_deletion' => ProjectStatus::class,
            'archived_at' => 'immutable_datetime',
            'deletion_scheduled_at' => 'immutable_datetime',
            'deletion_due_at' => 'immutable_datetime',
            'deletion_scheduled_with_org' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
