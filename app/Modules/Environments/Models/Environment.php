<?php

declare(strict_types=1);

namespace App\Modules\Environments\Models;

use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
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
 */
#[UseFactory(EnvironmentFactory::class)]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use BelongsToOrganization, HasFactory, HasPublicId, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = ['organization_id', 'project_id', 'name', 'key', 'color', 'position', 'status', 'current_policy_id'];

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
        ];
    }
}
