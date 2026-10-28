<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Protection settings of an environment, one immutable row per version. Changing protection inserts the next
        // version and moves environments.current_policy_id; no row is ever updated.
        Schema::create('environment_policies', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantEnvironment();
            $table->unsignedInteger('version');
            $table->boolean('is_protected')->default(false);
            $table->boolean('require_reason')->default(false);
            $table->unsignedTinyInteger('required_approvals')->default(0);
            $table->boolean('allow_self_approval')->default(false);
            $table->unsignedInteger('approval_ttl_hours')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamp('created_at', 6)->nullable();

            $table->unique(['environment_id', 'version']);
            $table->unique(['id', 'environment_id']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->char('current_policy_id', 26)->nullable()->after('status');
            $table->foreign('current_policy_id')->references('id')->on('environment_policies')->nullOnDelete();
        });

        // Who added a person to a project, for the members list and the audit trail.
        Schema::table('project_memberships', function (Blueprint $table) {
            $table->char('added_by', 26)->nullable()->after('role');
            $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_memberships', function (Blueprint $table) {
            $table->dropForeign(['added_by']);
            $table->dropColumn('added_by');
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropForeign(['current_policy_id']);
            $table->dropColumn('current_policy_id');
        });

        Schema::dropIfExists('environment_policies');
    }
};
