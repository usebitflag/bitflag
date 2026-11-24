<?php

declare(strict_types=1);

use App\Shared\Tenancy\Schema\TenantColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every kind of credential: server, browser and management keys, and personal access tokens. Only a peppered
        // hash of the secret is stored; the secret itself is shown once when the credential is issued.
        Schema::create('credentials', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantProject();
            // Required for server, browser and management keys; null for a personal token.
            $table->char('environment_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('type', 32);
            $table->string('label', 60);
            $table->string('display_prefix', 24);
            $table->char('last4', 4);
            $table->binary('secret_hash', 32, true)->unique();
            $table->unsignedTinyInteger('pepper_id');
            $table->json('scopes');
            $table->unsignedSmallInteger('ttl_days')->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->string('status', 32)->default('active');
            $table->char('rotated_from_id', 26)->nullable();
            $table->char('replaced_by_id', 26)->nullable();
            $table->timestamp('overlap_ends_at', 6)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            // Null when the system revoked it.
            $table->char('revoked_by', 26)->nullable();
            $table->string('revoke_reason', 32)->nullable();
            $table->timestamp('last_used_at', 6)->nullable();
            $table->string('last_used_client', 60)->nullable();
            $table->string('audience', 32)->default('api');
            // Kept after the person leaves: management keys belong to the project, not to their creator.
            $table->char('created_by', 26)->nullable();
            $table->timestamps(6);

            $table->foreign(['environment_id', 'project_id', 'organization_id'], TenantColumns::constraintName('credentials', ['environment_id', 'project_id', 'organization_id']))
                ->references(['id', 'project_id', 'organization_id'])->on('environments')->restrictOnDelete();
            $table->index(['organization_id', 'project_id', 'environment_id', 'status'], 'credentials_scope_status_index');
            $table->index(['organization_id', 'user_id', 'type', 'status'], 'credentials_user_status_index');
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'overlap_ends_at']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
