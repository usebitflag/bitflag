<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // After an email change the old address is told, and kept for 30 days so the notice can be sent and the change can be
        // investigated; the daily prune clears it.
        Schema::table('users', function (Blueprint $table) {
            $table->string('previous_email', 254)->nullable()->after('pending_email');
            $table->timestamp('email_changed_at', 6)->nullable()->after('previous_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['previous_email', 'email_changed_at']);
        });
    }
};
