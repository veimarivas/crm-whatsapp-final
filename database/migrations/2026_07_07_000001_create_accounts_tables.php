<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cuentas (multi-tenant): cada instalación se agrupa por account.
// users.account_id + users.account_role definen la membresía
// (equivalente a profiles.account_id/account_role del original).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('default_currency', 3)->default('BOB');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        Schema::create('account_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // SHA-256 hex del token del link
            $table->string('role', 20); // admin | agent | viewer (nunca owner)
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('member_presence', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('online'); // online | away
            $table->timestamp('last_seen_at')->useCurrent();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_presence');
        Schema::dropIfExists('account_invitations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });
        Schema::dropIfExists('accounts');
    }
};
