<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantOrganization();
            // The public ID of the user or token, for example usr_01j9x3….
            $table->string('actor_key', 40);
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->enum('status', ['in_progress', 'completed']);
            $table->smallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->string('result_type', 32)->nullable();
            $table->char('result_id', 26)->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamp('expires_at', 6)->nullable();

            // The unique index is the reservation: only one request can hold a key.
            $table->unique(['organization_id', 'actor_key', 'operation', 'idempotency_key'], 'idempotency_records_key_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
