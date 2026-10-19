<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A small editable tenant record that exercises the shared action pattern in tests.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_widgets', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantProject();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_widgets');
    }
};
