<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A JSON export of a project, built in the background and kept for 24 hours. It never holds a credential.
        Schema::create('project_exports', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantProject();
            $table->char('requested_by', 26)->nullable();
            $table->string('status', 32)->default('queued');
            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'project_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_exports');
    }
};
