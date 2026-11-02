<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * Each file in database/migrations returns an anonymous class extending this:
 *
 *   return new class extends Migration {
 *       public function up(): void   { Schema::create('posts', function (Blueprint $table) { ... }); }
 *       public function down(): void { Schema::dropIfExists('posts'); }
 *   };
 */
abstract class Migration
{
    abstract public function up(): void;

    abstract public function down(): void;
}
