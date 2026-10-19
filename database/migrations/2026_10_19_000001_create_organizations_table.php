<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tenant root. It is not itself tenant-scoped.
        Schema::create('organizations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name', 120);
            $table->string('slug', 40)->unique();
            $table->string('status', 32)->default('active');
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
