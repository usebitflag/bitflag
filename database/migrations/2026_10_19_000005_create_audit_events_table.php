<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantEnvironment(nullable: true);
            $table->enum('actor_type', ['user', 'management_token', 'system', 'support']);
            $table->char('actor_id', 26)->nullable();
            $table->string('actor_display', 120);
            $table->char('via_token_id', 26)->nullable();
            $table->char('token_creator_id', 26)->nullable();
            $table->enum('channel', ['dashboard', 'api', 'cli', 'mcp', 'system', 'support']);
            $table->string('action', 64);
            $table->string('target_type', 32);
            $table->char('target_id', 26)->nullable();
            $table->string('target_display', 160)->nullable();
            $table->unsignedInteger('from_revision')->nullable();
            $table->unsignedInteger('to_revision')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_id', 40)->nullable();
            $table->string('correlation_id', 40)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at', 6);

            $table->index(['organization_id', 'occurred_at', 'id']);
            $table->index(['organization_id', 'project_id', 'occurred_at']);
            $table->index(['organization_id', 'target_type', 'target_id']);
            $table->index(['organization_id', 'action', 'occurred_at']);
            $table->index(['organization_id', 'actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
