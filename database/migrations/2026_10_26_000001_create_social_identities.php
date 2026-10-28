<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person who signs up with a provider has no password until they set one.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        // The link between a person and an account at a provider. It is keyed on the provider's own user id, never the
        // email, so a changed email at the provider does not move or open an account.
        Schema::create('social_identities', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            // The verified email the provider gave when the link was made, for the person's own reference.
            $table->string('email', 254);
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('last_used_at', 6)->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
