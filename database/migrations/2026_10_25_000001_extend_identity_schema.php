<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // There is no "remember me": a session already lasts up to thirty days.
            $table->dropColumn('remember_token');

            $table->string('pending_email', 254)->nullable()->after('email_verified_at');
            $table->timestamp('password_changed_at', 6)->useCurrent()->after('password');
            $table->boolean('remember_org_choice')->default(false)->after('last_environment_id');
            $table->timestamp('last_active_at', 6)->nullable()->after('remember_org_choice');
            // Keeps audit attribution readable after the account is anonymized.
            $table->string('deleted_display_name', 120)->nullable()->after('last_active_at');
        });

        // Second factors belong to a person, not an organization. A row without confirmed_at is an enrollment in
        // progress.
        Schema::create('mfa_factors', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('type', 32);
            $table->text('secret');
            $table->timestamp('confirmed_at', 6)->nullable();
            // The 30-second step of the last accepted code; a code at or below it is a replay.
            $table->unsignedBigInteger('last_used_timestep')->nullable();
            $table->timestamp('last_used_at', 6)->nullable();
            $table->timestamp('created_at', 6)->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'type']);
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->binary('code_hash', 32, true);
            $table->timestamp('used_at', 6)->nullable();
            $table->timestamp('created_at', 6)->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'code_hash']);
        });

        Schema::create('trusted_browsers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->binary('token_hash', 32, true)->unique();
            $table->string('device_label', 120);
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('expires_at', 6);
            $table->timestamp('last_used_at', 6)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('known_devices', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->binary('device_hash', 32, true);
            $table->string('device_label', 120);
            $table->timestamp('first_seen_at', 6);
            $table->timestamp('last_seen_at', 6);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'device_hash']);
        });

        // The security log is not the customer audit. It outlives the account row (user_id is cleared, not deleted)
        // and is pruned by age. An attempt against an unknown address keeps only a hash of the address.
        Schema::create('security_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->nullable();
            $table->binary('email_hash', 32, true)->nullable();
            $table->string('type', 48);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at', 6);

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->unsignedInteger('created_at')->default(0)->after('last_activity');
            $table->string('device_label', 120)->nullable()->after('created_at');
            $table->string('location_estimate', 120)->nullable()->after('device_label');
            $table->char('trusted_browser_id', 26)->nullable()->after('location_estimate');

            $table->foreign('trusted_browser_id')->references('id')->on('trusted_browsers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropForeign(['trusted_browser_id']);
            $table->dropColumn(['created_at', 'device_label', 'location_estimate', 'trusted_browser_id']);
        });

        Schema::dropIfExists('security_events');
        Schema::dropIfExists('known_devices');
        Schema::dropIfExists('trusted_browsers');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_factors');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'password_changed_at', 'remember_org_choice', 'last_active_at', 'deleted_display_name']);
            $table->rememberToken();
        });
    }
};
