<?php

declare(strict_types=1);

namespace App\Modules\Environments\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One version of an environment's protection settings. Versions are immutable: a change inserts the next version, so
 * every change set can name the exact policy it was reviewed under.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $environment_id
 * @property int $version
 * @property bool $is_protected
 * @property bool $require_reason
 * @property int $required_approvals
 * @property bool $allow_self_approval
 * @property int|null $approval_ttl_hours
 */
class EnvironmentPolicy extends Model
{
    use BelongsToOrganization, HasUlids, UsesMicrosecondTimestamps;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'project_id', 'environment_id', 'version', 'is_protected', 'require_reason',
        'required_approvals', 'allow_self_approval', 'approval_ttl_hours', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_protected' => 'boolean',
            'require_reason' => 'boolean',
            'required_approvals' => 'integer',
            'allow_self_approval' => 'boolean',
            'approval_ttl_hours' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('An environment policy is immutable; insert the next version instead.');
        });

        static::deleting(static function (): never {
            throw new LogicException('An environment policy is never deleted.');
        });
    }
}
