<?php

declare(strict_types=1);

use Core\Database\Blueprint;
use Core\Database\Migration;
use Core\Database\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name', 100);
            $table->string('token', 64)->unique();   // HMAC-SHA256 of the secret, never the secret itself
            $table->text('abilities')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
