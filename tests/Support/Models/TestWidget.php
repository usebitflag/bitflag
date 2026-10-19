<?php

declare(strict_types=1);

namespace Tests\Support\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use App\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $name
 * @property int $version
 */
final class TestWidget extends Model
{
    use BelongsToOrganization, HasUlids, UsesMicrosecondTimestamps;

    protected $table = 'test_widgets';

    /** @var list<string> */
    protected $fillable = ['organization_id', 'project_id', 'name', 'version'];
}
