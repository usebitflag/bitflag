<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantOrganization();
            $table->string('name', 80);
            $table->string('key', 40);
            $table->string('description', 280)->nullable();
            $table->enum('status', ['active', 'archived', 'deletion_scheduled', 'deleted'])->default('active');
            $table->timestamps(6);

            $table->unique(['organization_id', 'key']);
            // Target of the composite foreign keys that tie child rows to a project of the same organization.
            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
