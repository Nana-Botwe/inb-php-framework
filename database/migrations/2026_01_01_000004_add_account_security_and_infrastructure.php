<?php

use Core\Database\Blueprint;
use Core\Database\Migration;
use Core\Database\Schema;

/**
 * Security review follow-up:
 *  - users: two-factor auth, security stamp (revokes every session), password_changed_at
 *  - payments: last_checked_at (reconciliation) + payment_webhook_events (persisted, retryable webhooks)
 *  - rate_limits / sessions / cache: shared database stores for multi-server deployments
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();            // encrypted
            $table->text('two_factor_recovery_codes')->nullable();    // encrypted list of hashes
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->string('security_stamp', 64)->nullable();         // rotating it signs out every session
            $table->dateTime('password_changed_at')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dateTime('last_checked_at')->nullable();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30);
            $table->string('event_type', 100)->nullable();
            $table->string('reference', 150)->nullable()->index();
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->string('status', 20)->default('received')->index();   // received | processed | unmatched | failed
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'payload_hash']);
        });

        Schema::create('rate_limits', function (Blueprint $table) {
            $table->char('key', 64)->primary();
            $table->integer('attempts');
            $table->bigInteger('reset_at')->index();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->bigInteger('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->char('key', 64)->primary();
            $table->longText('value');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('rate_limits');
        Schema::dropIfExists('payment_webhook_events');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('last_checked_at');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'security_stamp', 'password_changed_at');
        });
    }
};
