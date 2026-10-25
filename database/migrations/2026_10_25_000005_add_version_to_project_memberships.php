<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member's role is edited from a list that may be out of date, so changing it checks the version it was read at.
        Schema::table('project_memberships', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('added_by');
        });
    }

    public function down(): void
    {
        Schema::table('project_memberships', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
