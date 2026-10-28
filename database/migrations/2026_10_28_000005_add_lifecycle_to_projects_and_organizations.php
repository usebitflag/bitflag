<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Where a project goes back to when its deletion is cancelled.
            $table->string('status_before_deletion', 32)->nullable()->after('status');
            $table->timestamp('archived_at', 6)->nullable()->after('created_by');
            $table->char('archived_by', 26)->nullable()->after('archived_at');
            $table->timestamp('deletion_scheduled_at', 6)->nullable()->after('archived_by');
            $table->timestamp('deletion_due_at', 6)->nullable()->after('deletion_scheduled_at');
            $table->char('deletion_scheduled_by', 26)->nullable()->after('deletion_due_at');
            // True when the organization scheduled it, so cancelling the organization restores only those.
            $table->boolean('deletion_scheduled_with_org')->default(false)->after('deletion_scheduled_by');
            // The time of the purge. The row stays as a tombstone that audit entries point at.
            $table->timestamp('deleted_at', 6)->nullable()->after('deletion_scheduled_with_org');

            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deletion_scheduled_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'deletion_due_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('deletion_scheduled_at', 6)->nullable();
            $table->timestamp('deletion_due_at', 6)->nullable();
            $table->char('deletion_scheduled_by', 26)->nullable();
            $table->timestamp('deleted_at', 6)->nullable();

            $table->foreign('deletion_scheduled_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'deletion_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['status', 'deletion_due_at']);
            $table->dropForeign(['deletion_scheduled_by']);
            $table->dropColumn(['deletion_scheduled_at', 'deletion_due_at', 'deletion_scheduled_by', 'deleted_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['status', 'deletion_due_at']);
            $table->dropForeign(['archived_by']);
            $table->dropForeign(['deletion_scheduled_by']);
            $table->dropColumn([
                'status_before_deletion', 'archived_at', 'archived_by', 'deletion_scheduled_at', 'deletion_due_at',
                'deletion_scheduled_by', 'deletion_scheduled_with_org', 'deleted_at',
            ]);
        });
    }
};
