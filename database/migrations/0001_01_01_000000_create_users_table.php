<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at', 6)->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('timezone', 64)->default('UTC');
            $table->enum('status', ['active', 'deactivated'])->default('active');
            // The last scope the person worked in. Foreign keys are added once those tables exist.
            $table->char('last_organization_id', 26)->nullable();
            $table->char('last_project_id', 26)->nullable();
            $table->char('last_environment_id', 26)->nullable();
            $table->timestamps(6);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at', 6)->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->char('user_id', 26)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
