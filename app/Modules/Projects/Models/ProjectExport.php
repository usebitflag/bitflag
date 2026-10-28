<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Domain\ExportStatus;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A requested export of a project's data.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $requested_by
 * @property ExportStatus $status
 * @property string|null $file_path
 * @property int|null $bytes
 * @property string|null $sha256
 * @property string|null $error_code
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 */
class ProjectExport extends Model
{
    use BelongsToOrganization, HasUlids, UsesMicrosecondTimestamps;

    protected $fillable = ['organization_id', 'project_id', 'requested_by', 'status', 'file_path', 'bytes', 'sha256', 'error_code', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'bytes' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
