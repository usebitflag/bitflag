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
            $table->string('time_zone', 64)->default('UTC')->after('description');
            // What new flags start with. The defaults are stored here and enforced when a flag is created.
            $table->string('flag_key_format', 32)->default('kebab')->after('time_zone');
            $table->unsignedSmallInteger('stale_after_days')->default(30)->after('flag_key_format');
            $table->boolean('require_flag_description')->default(true)->after('stale_after_days');
            $table->boolean('require_flag_owner')->default(true)->after('require_flag_description');
            $table->boolean('client_visible_by_default')->default(false)->after('require_flag_owner');
            // Set when the first credential is issued or the first revision is published; the key is fixed from then on.
            $table->timestamp('key_locked_at', 6)->nullable()->after('status');
            // Settings form concurrency.
            $table->unsignedInteger('version')->default(1)->after('key_locked_at');
            $table->char('created_by', 26)->nullable()->after('version');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'time_zone', 'flag_key_format', 'stale_after_days', 'require_flag_description',
                'require_flag_owner', 'client_visible_by_default', 'key_locked_at', 'version', 'created_by',
            ]);
        });
    }
};
