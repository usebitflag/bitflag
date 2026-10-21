<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantOrganization();
            $table->char('user_id', 26);
            $table->enum('role', ['owner', 'member']);
            $table->timestamps(6);

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('project_memberships', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantProject();
            $table->char('user_id', 26);
            $table->enum('role', ['owner', 'publisher', 'developer', 'viewer']);
            $table->timestamps(6);

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['organization_id', 'project_id', 'user_id']);
            $table->index(['user_id', 'organization_id']);
        });

        // The remembered scope points at rows that can disappear; clear it rather than block the deletion.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('last_organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('last_project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('last_environment_id')->references('id')->on('environments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['last_organization_id']);
            $table->dropForeign(['last_project_id']);
            $table->dropForeign(['last_environment_id']);
        });

        Schema::dropIfExists('project_memberships');
        Schema::dropIfExists('organization_memberships');
    }
};
