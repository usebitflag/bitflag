<?php

declare(strict_types=1);

namespace App\Modules\Environments\Models;

use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $name
 * @property string $key
 * @property EnvironmentColor $color
 * @property int $position
 * @property EnvironmentStatus $status
 * @property string|null $current_policy_id
 * @property CarbonImmutable|null $first_published_at
 * @property list<string> $allowed_origins
 * @property int|null $default_max_snapshot_age_seconds
 * @property CarbonImmutable|null $archived_at
 * @property string|null $archived_by
 * @property int $version
 * @property string|null $created_by
 */
#[UseFactory(EnvironmentFactory::class)]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use BelongsToOrganization, HasFactory, HasPublicId, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = [
        'organization_id', 'project_id', 'name', 'key', 'color', 'position', 'status', 'current_policy_id', 'allowed_origins',
        'default_max_snapshot_age_seconds', 'created_by',
    ];

    public static function publicIdPrefix(): IdPrefix
    {
        return IdPrefix::Environment;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => EnvironmentColor::class,
            'status' => EnvironmentStatus::class,
            'first_published_at' => 'immutable_datetime',
            'allowed_origins' => 'array',
            'default_max_snapshot_age_seconds' => 'integer',
            'archived_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
