<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tables that exist only in the test database, so tests never create tables inside a transaction.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('macro_probe', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantEnvironment();
        });

        Schema::create('public_id_probes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('slug')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_id_probes');
        Schema::dropIfExists('macro_probe');
    }
};
