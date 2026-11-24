<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Domain\TeamSize;
use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant root. Every other tenant record belongs to one organization.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property OrganizationStatus $status
 * @property bool $require_mfa
 * @property int|null $session_idle_timeout_minutes
 * @property list<string> $allowed_email_domains
 * @property TeamSize|null $team_size_hint
 * @property array<string, mixed> $onboarding
 * @property int $version
 * @property string|null $created_by
 * @property CarbonImmutable|null $deletion_scheduled_at
 * @property CarbonImmutable|null $deletion_due_at
 * @property string|null $deletion_scheduled_by
 * @property CarbonImmutable|null $deleted_at
 */
#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasPublicId, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = ['name', 'slug', 'status', 'require_mfa', 'session_idle_timeout_minutes', 'allowed_email_domains', 'team_size_hint', 'onboarding', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['require_mfa' => false, 'allowed_email_domains' => '[]', 'onboarding' => '[]', 'version' => 1];

    public static function publicIdPrefix(): IdPrefix
    {
        return IdPrefix::Organization;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'require_mfa' => 'boolean',
            'session_idle_timeout_minutes' => 'integer',
            'allowed_email_domains' => 'array',
            'team_size_hint' => TeamSize::class,
            'onboarding' => 'array',
            'version' => 'integer',
            'deletion_scheduled_at' => 'immutable_datetime',
            'deletion_due_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
