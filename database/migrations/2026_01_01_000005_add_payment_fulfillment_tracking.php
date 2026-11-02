<?php

declare(strict_types=1);

use Core\Database\Blueprint;
use Core\Database\Migration;
use Core\Database\Schema;
use Core\DB;

/**
 * Durable payment fulfilment ("outbox" in the payment row itself):
 *  - fulfilled_at              set only after every "payment.succeeded" listener finished
 *  - fulfillment_locked_until  lease held by the process running the listeners (also the retry back-off)
 *  - fulfillment_attempts / fulfillment_error   for retries and alerts
 *
 * A payment that is "success" but has no fulfilled_at is retried by payments:reconcile — so a crash or a
 * failing listener after the payment was marked paid can no longer lose the order.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dateTime('fulfilled_at')->nullable();
            $table->dateTime('fulfillment_locked_until')->nullable();
            $table->integer('fulfillment_attempts')->default(0);
            $table->text('fulfillment_error')->nullable();
        });

        // Payments completed before this migration were already fulfilled the old way: don't run them again.
        DB::table('payments')->where('status', 'success')->update(['fulfilled_at' => date('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('fulfilled_at', 'fulfillment_locked_until', 'fulfillment_attempts', 'fulfillment_error');
        });
    }
};
