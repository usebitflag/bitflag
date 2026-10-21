<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function probe_foreign_keys(): array
{
    $rows = DB::select(
        'select constraint_name as constraint_name, referenced_table_name as referenced_table_name, group_concat(column_name order by ordinal_position) as cols
         from information_schema.key_column_usage
         where table_schema = ? and table_name = ? and referenced_table_name is not null
         group by constraint_name, referenced_table_name',
        [DB::getDatabaseName(), 'macro_probe'],
    );

    $keys = array_map(static fn (object $row): string => "{$row->cols} -> {$row->referenced_table_name}", $rows);
    sort($keys);

    return $keys;
}

it('adds the organization, project and environment ownership constraints', function () {
    expect(probe_foreign_keys())->toBe([
        'environment_id,project_id,organization_id -> environments',
        'organization_id -> organizations',
        'project_id,organization_id -> projects',
    ]);
});

it('adds an index that starts with organization_id', function () {
    $leading = DB::selectOne(
        'select 1 as found from information_schema.statistics where table_schema = ? and table_name = ? and column_name = ? and seq_in_index = 1',
        [DB::getDatabaseName(), 'macro_probe', 'organization_id'],
    );

    expect($leading)->not->toBeNull();
});

it('rejects a row whose project belongs to another organization', function () {
    [$mine, $theirs] = Organization::factory()->count(2)->create();
    $project = Project::factory()->create(['organization_id' => $mine->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id, 'organization_id' => $mine->id]);

    $row = fn (string $organizationId) => DB::table('macro_probe')->insert([
        'id' => '01j9x3k7q2m5n8p0r4s6t8v0wy',
        'organization_id' => $organizationId,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    expect(fn () => $row($theirs->id))->toThrow(QueryException::class);

    $row($mine->id);

    expect(Tenancy::crossTenant('probe', fn () => DB::table('macro_probe')->count()))->toBe(1);
});
