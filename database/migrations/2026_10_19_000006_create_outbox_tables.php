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
        // Events about an organization carry organization_id; events about a person before or outside any
        // organization (sign-up, password reset) carry user_id; platform maintenance carries neither.
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('scope', 32);
            $table->char('organization_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->char('project_id', 26)->nullable();
            $table->char('environment_id', 26)->nullable();
            $table->string('type', 64);
            $table->string('aggregate_type', 32);
            $table->char('aggregate_id', 26);
            $table->json('payload');
            $table->string('request_id', 40)->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamp('available_at', 6);
            $table->timestamp('dispatched_at', 6)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claimed_by', 64)->nullable();
            $table->timestamp('claimed_until', 6)->nullable();
            $table->string('last_error', 500)->nullable();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['scope', 'user_id', 'occurred_at']);
            $table->index(['dispatched_at', 'available_at']);
        });

        // One row per message and consumer, so each consumer retries and replays independently. The receipt, not the
        // queue, is the record of outstanding work.
        Schema::create('outbox_receipts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('outbox_message_id', 26);
            $table->string('scope', 32);
            $table->char('organization_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('consumer', 100);
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('processed_at', 6)->nullable();
            $table->string('lease_owner', 64)->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->timestamp('last_signalled_at', 6)->nullable();
            $table->string('last_error', 500)->nullable();

            $table->foreign('outbox_message_id')->references('id')->on('outbox_messages')->cascadeOnDelete();
            $table->unique(['outbox_message_id', 'consumer']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'lease_until']);
            $table->index(['organization_id', 'status', 'next_attempt_at']);
        });

        foreach (['outbox_messages', 'outbox_receipts'] as $name) {
            DB::statement("alter table `{$name}` add constraint `{$name}_organization_scope_check` check (`scope` <> 'organization' or `organization_id` is not null)");
            DB::statement("alter table `{$name}` add constraint `{$name}_account_scope_check` check (`scope` <> 'account' or `user_id` is not null)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_receipts');
        Schema::dropIfExists('outbox_messages');
    }
};
