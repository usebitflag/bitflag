<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin console identities only. They are never joined to users.
        Schema::create('staff_users', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status', 32)->default('active');
            $table->rememberToken();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_users');
    }
};
