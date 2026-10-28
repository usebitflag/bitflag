<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('require_mfa')->default(false)->after('status');
            // Null means the platform's 7 days; otherwise 60, 720 or 1440 minutes.
            $table->unsignedInteger('session_idle_timeout_minutes')->nullable()->after('require_mfa');
            // An empty list allows any domain.
            $table->json('allowed_email_domains')->after('session_idle_timeout_minutes');
            $table->string('team_size_hint', 32)->nullable()->after('allowed_email_domains');
            // Where the sign-up wizard stands, so it can resume: {plan_chosen_at, team_step_done_at}.
            $table->json('onboarding')->after('team_size_hint');
            $table->unsignedInteger('version')->default(1)->after('onboarding');
            $table->char('created_by', 26)->nullable()->after('version');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->index(['organization_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'role']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['require_mfa', 'session_idle_timeout_minutes', 'allowed_email_domains', 'team_size_hint', 'onboarding', 'version', 'created_by']);
        });
    }
};
