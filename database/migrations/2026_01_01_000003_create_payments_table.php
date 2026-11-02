<?php

use Core\Database\Blueprint;
use Core\Database\Migration;
use Core\Database\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 30);
            $table->string('reference', 100)->unique();
            $table->string('gateway_reference', 150)->index();
            $table->bigInteger('amount_minor')->unsigned();      // pesewas / kobo / cents — never floats
            $table->char('currency', 3);
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending')->index();
            $table->string('description', 200)->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
