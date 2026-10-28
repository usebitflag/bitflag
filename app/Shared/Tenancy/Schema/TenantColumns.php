<?php

declare(strict_types=1);

namespace App\Shared\Tenancy\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * Blueprint macros that give a table its tenant columns and composite ownership constraints, so a row cannot point at
 * a project or environment that belongs to another organization.
 *
 *   $table->tenantOrganization();          organization_id
 *   $table->tenantProject();               + project_id, FK (project_id, organization_id) -> projects
 *   $table->tenantEnvironment();           + environment_id, FK (environment_id, project_id, organization_id) -> environments
 *
 * Each macro also adds an index that starts with organization_id. Pass true to allow NULL scope columns, for records
 * that can sit at organization level.
 */
final class TenantColumns
{
    public static function register(): void
    {
        Blueprint::macro('tenantOrganization', function (): void {
            /** @var Blueprint $this */
            $this->char('organization_id', 26);
            $this->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Blueprint::macro('tenantProject', function (bool $nullable = false): void {
            /** @var Blueprint $this */
            $this->tenantOrganization();
            $column = $this->char('project_id', 26);
            if ($nullable) {
                $column->nullable();
            }
            $this->foreign(['project_id', 'organization_id'], TenantColumns::constraintName($this->getTable(), ['project_id', 'organization_id']))
                ->references(['id', 'organization_id'])->on('projects')->restrictOnDelete();
            $this->index(['organization_id', 'project_id'], TenantColumns::constraintName($this->getTable(), ['organization_id', 'project_id'], 'index'));
        });

        Blueprint::macro('tenantEnvironment', function (bool $nullable = false): void {
            /** @var Blueprint $this */
            $this->tenantProject($nullable);
            $column = $this->char('environment_id', 26);
            if ($nullable) {
                $column->nullable();
            }
            $this->foreign(['environment_id', 'project_id', 'organization_id'], TenantColumns::constraintName($this->getTable(), ['environment_id', 'project_id', 'organization_id']))
                ->references(['id', 'project_id', 'organization_id'])->on('environments')->restrictOnDelete();
            $this->index(['organization_id', 'project_id', 'environment_id'], TenantColumns::constraintName($this->getTable(), ['organization_id', 'project_id', 'environment_id'], 'index'));
        });
    }

    /**
     * The name Laravel would give the foreign key or index, or a short hashed one when that would be over MySQL's 64
     * characters.
     *
     * @param  list<string>  $columns
     */
    public static function constraintName(string $table, array $columns, string $type = 'foreign'): string
    {
        $name = strtolower($table.'_'.implode('_', $columns).'_'.$type);

        return strlen($name) <= 64 ? $name : 'fk_'.substr(sha1($name), 0, 40);
    }
}
