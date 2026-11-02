<?php

declare(strict_types=1);

use Core\Database\Blueprint;
use Core\Database\Migration;
use Core\Database\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->tinyInteger('attempts')->unsigned();
            $table->integer('reserved_at')->unsigned()->nullable();
            $table->integer('available_at')->unsigned();
            $table->integer('created_at')->unsigned();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->dateTime('failed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
