<?php

declare(strict_types=1);

use App\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function database_tables(): array
{
    return array_map(
        static fn (object $row): string => (string) $row->table_name,
        DB::select('select table_name as table_name from information_schema.tables where table_schema = ? and table_type = ?', [DB::getDatabaseName(), 'BASE TABLE']),
    );
}

it('classifies every table as tenant, scoped or global', function () {
    $classified = [
        ...array_keys(config('tenancy.tenant')),
        ...config('tenancy.scoped'),
        ...config('tenancy.global'),
    ];

    expect(array_diff(database_tables(), $classified))->toBe([], 'Add the new table to config/tenancy.php.')
        ->and($classified)->toBe(array_values(array_unique($classified)), 'A table is classified twice.');
});

it('gives every tenant table a required organization_id, a leading index and a scoped model', function () {
    $existing = database_tables();
    $checked = 0;

    foreach (config('tenancy.tenant') as $table => $model) {
        if (! in_array($table, $existing, true)) {
            continue;
        }
        $checked++;

        $column = DB::selectOne(
            'select is_nullable as is_nullable, column_type as column_type from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, 'organization_id'],
        );

        expect($column)->not->toBeNull("{$table} has no organization_id")
            ->and($column->is_nullable)->toBe('NO', "{$table}.organization_id must be NOT NULL")
            ->and($column->column_type)->toBe('char(26)');

        $leading = DB::selectOne(
            'select 1 as found from information_schema.statistics where table_schema = ? and table_name = ? and column_name = ? and seq_in_index = 1',
            [DB::getDatabaseName(), $table, 'organization_id'],
        );

        expect($leading)->not->toBeNull("{$table} needs an index that starts with organization_id");

        expect(class_exists($model))->toBeTrue("{$table} has no model {$model}")
            ->and(class_uses_recursive($model))->toHaveKey(BelongsToOrganization::class);
    }

    expect($checked)->toBeGreaterThan(0);
});

it('requires organization_id only for organization-scoped rows in scoped tables', function () {
    $existing = database_tables();

    foreach (config('tenancy.scoped') as $table) {
        if (! in_array($table, $existing, true)) {
            continue;
        }

        $columns = array_map(
            static fn (object $row): string => (string) $row->column_name,
            DB::select('select column_name as column_name from information_schema.columns where table_schema = ? and table_name = ?', [DB::getDatabaseName(), $table]),
        );

        expect($columns)->toContain('scope', 'organization_id');
    }
});
