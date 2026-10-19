<?php

declare(strict_types=1);

use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantQueryGuard;

it('flags simple unscoped statements', function (string $sql) {
    expect(TenantQueryGuard::violates($sql))->toBeTrue();
})->with([
    'select' => 'select * from `projects` where `id` = ?',
    'update' => 'update `projects` set `name` = ? where `id` = ?',
    'delete' => 'delete from `environments` where `id` = ?',
    'membership read' => 'select * from `organization_memberships` where `user_id` = ?',
]);

it('accepts statements that name organization_id', function (string $sql) {
    expect(TenantQueryGuard::violates($sql))->toBeFalse();
})->with([
    'scoped select' => 'select * from `projects` where `projects`.`organization_id` = ? and `id` = ?',
    'insert' => 'insert into `projects` (`id`, `organization_id`, `name`) values (?, ?, ?)',
]);

it('ignores tables that are not tenant tables and schema changes', function (string $sql) {
    expect(TenantQueryGuard::violates($sql))->toBeFalse();
})->with([
    'global table' => 'select * from `users` where `id` = ?',
    'information schema' => 'select table_name from information_schema.tables where table_schema = ?',
    'ddl' => 'alter table `projects` add column `x` int',
    'transaction' => 'start transaction',
]);

it('records a real query that has no organization predicate', function () {
    DB::table('projects')->count();

    expect(TenantQueryGuard::takeViolations())->toHaveCount(1);
});

it('passes a query that names the organization', function () {
    DB::table('projects')->where('organization_id', 'x')->count();

    expect(TenantQueryGuard::takeViolations())->toBe([]);
});

it('passes queries made inside crossTenant', function () {
    Tenancy::crossTenant('guard test', fn () => DB::table('projects')->count());

    expect(TenantQueryGuard::takeViolations())->toBe([]);
});

it('skips queries while paused', function () {
    TenantQueryGuard::paused(fn () => DB::table('projects')->count());

    expect(TenantQueryGuard::takeViolations())->toBe([]);
});
