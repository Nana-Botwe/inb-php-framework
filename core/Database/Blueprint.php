<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * Describes a table in a migration:
 *
 *   Schema::create('posts', function (Blueprint $table) {
 *       $table->id();
 *       $table->foreignId('user_id')->constrained()->cascadeOnDelete();
 *       $table->string('title');
 *       $table->text('body')->nullable();
 *       $table->boolean('published')->default(false);
 *       $table->timestamps();
 *       $table->softDeletes();
 *   });
 */
final class Blueprint
{
    /** @var ColumnDefinition[] */
    public array $columns = [];

    /** @var array<int, array{type: string, columns: string[], name: string}> */
    public array $indexes = [];

    /** @var ForeignKeyDefinition[] */
    public array $foreignKeys = [];

    /** @var array<int, array{0: string, 1: mixed}> drop/rename commands for Schema::table() */
    public array $commands = [];

    public function __construct(public readonly string $table)
    {
    }

    // ------------------------------------------------------------------ keys

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($name);
    }

    public function increments(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'integer')->unsigned()->autoIncrement()->primary();
    }

    public function bigIncrements(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'bigInteger')->unsigned()->autoIncrement()->primary();
    }

    public function foreignId(string $name): ForeignIdColumnDefinition
    {
        $column = new ForeignIdColumnDefinition($this, $name, 'bigInteger');
        $column->unsigned();

        return $this->columns[] = $column;
    }

    public function foreign(string $column, ?string $name = null): ForeignKeyDefinition
    {
        return $this->foreignKeys[] = new ForeignKeyDefinition($column, $name ?? "{$this->table}_{$column}_foreign");
    }

    // ------------------------------------------------------------------ strings

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, 'string', ['length' => $length]);
    }

    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, 'char', ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'text');
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'mediumText');
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'longText');
    }

    /** @param string[] $allowed */
    public function enum(string $name, array $allowed): ColumnDefinition
    {
        return $this->addColumn($name, 'enum', ['allowed' => $allowed]);
    }

    public function uuid(string $name = 'uuid'): ColumnDefinition
    {
        return $this->addColumn($name, 'char', ['length' => 36]);
    }

    public function ipAddress(string $name = 'ip_address'): ColumnDefinition
    {
        return $this->addColumn($name, 'string', ['length' => 45]);
    }

    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    // ------------------------------------------------------------------ numbers

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'integer');
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'tinyInteger');
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'smallInteger');
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'bigInteger');
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'float');
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'double');
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'boolean');
    }

    // ------------------------------------------------------------------ dates & json

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'date');
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'dateTime');
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'timestamp');
    }

    public function time(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'time');
    }

    public function year(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'year');
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'json');
    }

    /** created_at and updated_at (nullable DATETIME) */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    /** deleted_at for models that use SoftDeletes */
    public function softDeletes(string $column = 'deleted_at'): ColumnDefinition
    {
        return $this->dateTime($column)->nullable();
    }

    // ------------------------------------------------------------------ indexes

    /** @param string|string[] $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->addIndex('index', (array) $columns, $name);
    }

    /** @param string|string[] $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->addIndex('unique', (array) $columns, $name);
    }

    /** Composite primary key. @param string|string[] $columns */
    public function primary(string|array $columns): void
    {
        $this->addIndex('primary', (array) $columns, null);
    }

    // ------------------------------------------------------------------ alter commands

    public function dropColumn(string ...$columns): void
    {
        foreach ($columns as $column) {
            $this->commands[] = ['dropColumn', $column];
        }
    }

    public function dropIndex(string $name): void
    {
        $this->commands[] = ['dropIndex', $name];
    }

    public function dropUnique(string $name): void
    {
        $this->commands[] = ['dropIndex', $name];
    }

    public function dropForeign(string $name): void
    {
        $this->commands[] = ['dropForeign', $name];
    }

    public function dropTimestamps(): void
    {
        $this->dropColumn('created_at', 'updated_at');
    }

    public function dropSoftDeletes(string $column = 'deleted_at'): void
    {
        $this->dropColumn($column);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $options */
    private function addColumn(string $name, string $type, array $options = []): ColumnDefinition
    {
        return $this->columns[] = new ColumnDefinition($this, $name, $type, $options);
    }

    /** @param string[] $columns */
    private function addIndex(string $type, array $columns, ?string $name): void
    {
        $this->indexes[] = [
            'type' => $type,
            'columns' => $columns,
            'name' => $name ?? strtolower($this->table . '_' . implode('_', $columns) . '_' . $type),
        ];
    }
}
