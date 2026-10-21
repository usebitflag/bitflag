<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantProject();
            $table->string('name', 40);
            $table->string('key', 40);
            $table->enum('color', ['slate', 'lilac', 'violet', 'indigo', 'ink']);
            $table->unsignedSmallInteger('position');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps(6);

            $table->unique(['organization_id', 'project_id', 'key']);
            // Target of the composite foreign keys that tie child rows to an environment of the same project.
            $table->unique(['id', 'project_id', 'organization_id']);
            $table->index(['organization_id', 'project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
