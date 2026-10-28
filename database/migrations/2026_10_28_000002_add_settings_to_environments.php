<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            // Set when the first revision is published; the key is fixed from then on.
            $table->timestamp('first_published_at', 6)->nullable()->after('current_policy_id');
            // Normalized browser origins (at most 20). An empty list refuses every browser-key request.
            $table->json('allowed_origins')->default(DB::raw('(JSON_ARRAY())'))->after('first_published_at');
            // Null means no limit.
            $table->unsignedInteger('default_max_snapshot_age_seconds')->nullable()->after('allowed_origins');
            $table->timestamp('archived_at', 6)->nullable()->after('default_max_snapshot_age_seconds');
            $table->char('archived_by', 26)->nullable()->after('archived_at');
            // Settings form concurrency.
            $table->unsignedInteger('version')->default(1)->after('archived_by');
            $table->char('created_by', 26)->nullable()->after('version');

            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropForeign(['archived_by']);
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'first_published_at', 'allowed_origins', 'default_max_snapshot_age_seconds', 'archived_at',
                'archived_by', 'version', 'created_by',
            ]);
        });
    }
};
