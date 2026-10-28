<?php

declare(strict_types=1);

use App\Shared\Tenancy\Schema\TenantColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['environment_id', 'project_id', 'organization_id'];

    public function up(): void
    {
        // An environment that was never published can be deleted, and the history of its creation stays in the audit log.
        // The entry keeps the environment's ID as a plain reference; the organization and project constraints remain.
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropForeign(TenantColumns::constraintName('audit_events', self::COLUMNS));
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->foreign(self::COLUMNS, TenantColumns::constraintName('audit_events', self::COLUMNS))
                ->references(['id', 'project_id', 'organization_id'])->on('environments')->restrictOnDelete();
        });
    }
};
