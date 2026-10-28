<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantOrganization();
            $table->string('email', 254);
            $table->string('org_role', 32);
            // queued while the inviter has not confirmed their own email; pending once sent.
            $table->string('status', 32);
            // Set when the email is sent and replaced on every resend; only the peppered hash is kept.
            $table->binary('token_hash', 32, true)->nullable()->unique();
            $table->char('invited_by', 26);
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->char('accepted_by', 26)->nullable();
            $table->timestamp('accepted_at', 6)->nullable();
            $table->char('revoked_by', 26)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('invited_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('accepted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'email']);
            // Target of the composite foreign key that ties a grant to an invitation of the same organization.
            $table->unique(['id', 'organization_id']);
        });

        // The projects an invitation adds the person to, with the role in each.
        Schema::create('invitation_project_grants', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantProject();
            $table->char('invitation_id', 26);
            $table->string('role', 32);
            $table->timestamps(6);

            $table->foreign(['invitation_id', 'organization_id'])->references(['id', 'organization_id'])->on('invitations')->cascadeOnDelete();
            $table->unique(['invitation_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_project_grants');
        Schema::dropIfExists('invitations');
    }
};
