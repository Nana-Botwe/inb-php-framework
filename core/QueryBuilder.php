<?php

declare(strict_types=1);

namespace Core;

use Closure;
use Core\Support\Collection;
use Core\Support\Paginator;
use InvalidArgumentException;
use LogicException;

/**
 * Fluent, injection-safe SQL builder (like Laravel's query builder).
 *
 *   DB::table('users')->where('active', 1)->where(fn ($q) => $q->where('role', 'admin')->orWhere('role', 'editor'))
 *       ->orderBy('name')->paginate(15);
 *   User::where('age', '>=', 18)->with('posts')->latest()->get();
 *
 * Values are ALWAYS bound as parameters. Table/column names are validated, so user input can
 * never change the SQL structure. Only the *Raw() methods accept SQL text — never pass user input there.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    /** @var string[] compiled select expressions */
    private array $columns = [];

    /** @var array<int, mixed> */
    private array $selectBindings = [];

    private bool $distinct = false;

    /** @var string[] */
    private array $joins = [];

    /** @var array<int, array{0: string, 1: string, 2: array<int, mixed>}> [boolean, sql, bindings] */
    private array $wheres = [];

    /** @var string[] */
    private array $groups = [];

    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    private array $havings = [];

    /** @var string[] */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var class-string<Model>|null */
    private ?string $model = null;

    /** @var array<string, Closure|null> relations to eager load */
    private array $eagerLoad = [];

    /** 'exclude' | 'with' | 'only' — for models that use SoftDeletes */
    private string $trashed = 'exclude';

    public function __construct(private readonly Database $db, private readonly string $table)
    {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function newQuery(): self
    {
        $query = new self($this->db, $this->table);
        $query->model = $this->model;

        return $query;
    }

    /** @param class-string<Model> $class Rows will be returned as instances of this model. */
    public function setModel(string $class): self
    {
        $this->model = $class;

        return $this;
    }

    /** @return class-string<Model>|null */
    public function getModel(): ?string
    {
        return $this->model;
    }

    // ------------------------------------------------------------------ select

    public function select(string ...$columns): self
    {
        $this->columns = [];
        $this->selectBindings = [];

        return $this->addSelect(...$columns);
    }

    public function addSelect(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->columns[] = $this->wrapColumn($column);
        }

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->columns[] = $expression;
        array_push($this->selectBindings, ...$bindings);

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    // ------------------------------------------------------------------ joins

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $operator = $this->checkOperator($operator);
        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $type,
            $this->wrapTable($table),
            $this->db->quoteIdentifier($first),
            $operator,
            $this->db->quoteIdentifier($second),
        );

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    // ------------------------------------------------------------------ where

    /**
     * where('email', $email)                 -> email = ?
     * where('age', '>', 18)                  -> age > ?
     * where('deleted_at', null)              -> deleted_at IS NULL
     * where(['active' => 1, 'role' => 'x'])  -> active = ? AND role = ?
     * where(fn ($q) => $q->where(..)->orWhere(..))  -> ( ... OR ... )
     */
    public function where(string|array|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof Closure) {
            $nested = $this->newQuery();
            $nested->trashed = 'with';
            $column($nested);
            [$sql, $bindings] = $nested->compileWhereClauses(false);
            if ($sql !== '') {
                $this->wheres[] = [$boolean, '(' . $sql . ')', $bindings];
            }

            return $this;
        }

        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->where((string) $key, '=', $val, $boolean);
            }

            return $this;
        }

        if (func_num_args() === 2 || ($value === null && !(is_string($operator) && in_array(strtoupper($operator), self::OPERATORS, true)))) {
            [$value, $operator] = [$operator, '='];
        }

        $operator = $this->checkOperator((string) $operator);
        $col = $this->db->quoteIdentifier($column);

        if ($value === null) {
            $this->wheres[] = [$boolean, $col . (in_array($operator, ['!=', '<>'], true) ? ' IS NOT NULL' : ' IS NULL'), []];
        } else {
            $this->wheres[] = [$boolean, "$col $operator ?", [$this->normaliseValue($value)]];
        }

        return $this;
    }

    public function orWhere(string|array|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereNot(string $column, mixed $value): self
    {
        return $this->where($column, '!=', $value);
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if ($values === []) {
            $this->wheres[] = [$boolean, $not ? '1 = 1' : '1 = 0', []];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            $boolean,
            $this->db->quoteIdentifier($column) . ($not ? ' NOT IN ' : ' IN ') . "($placeholders)",
            array_map([$this, 'normaliseValue'], array_values($values)),
        ];

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'AND', true);
    }

    /** @param array<int, mixed> $values */
    public function orWhereIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, $this->db->quoteIdentifier($column) . ' IS NULL', []];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, $this->db->quoteIdentifier($column) . ' IS NOT NULL', []];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }

    /** @param array{0: mixed, 1: mixed} $range */
    public function whereBetween(string $column, array $range, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            $boolean,
            $this->db->quoteIdentifier($column) . ' BETWEEN ? AND ?',
            [$this->normaliseValue($range[0] ?? null), $this->normaliseValue($range[1] ?? null)],
        ];

        return $this;
    }

    /** Search for text anywhere in one or more columns: ->whereLike(['name', 'email'], $term) */
    public function whereLike(string|array $columns, string $value): self
    {
        $pattern = '%' . addcslashes($value, '%_\\') . '%';
        $grammar = $this->db->grammar();
        $like = ' ' . $grammar->likeOperator() . ' ?' . $grammar->likeEscape();   // ILIKE on PostgreSQL (case-insensitive)

        return $this->where(function (self $q) use ($columns, $pattern, $like): void {
            foreach ((array) $columns as $column) {
                $q->orWhereRaw($this->db->quoteIdentifier($column) . $like, [$pattern]);
            }
        });
    }

    /**
     * Raw SQL condition. NEVER put user input in $sql — pass it in $bindings.
     *
     * @param array<int, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, $sql, array_map([$this, 'normaliseValue'], $bindings)];

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /** Run a callback on the query only when $value is truthy (handy for optional filters). */
    public function when(mixed $value, callable $callback, ?callable $default = null): self
    {
        if ($value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }

    // ------------------------------------------------------------------ grouping / ordering

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->db->quoteIdentifier($column);
        }

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $this->havings[] = [$this->db->quoteIdentifier($column) . ' ' . $this->checkOperator($operator) . ' ?', [$value]];

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->havings[] = [$sql, $bindings];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid order direction [$direction].");
        }

        $this->orders[] = $this->db->quoteIdentifier($column) . ' ' . $direction;

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    public function inRandomOrder(): self
    {
        $this->orders[] = $this->db->grammar()->random();

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset((max(1, $page) - 1) * $perPage)->limit($perPage);
    }

    // ------------------------------------------------------------------ models: eager loading / soft deletes / scopes

    /** Eager load relations to avoid N+1 queries: User::with('posts', 'profile')->get() */
    public function with(string|array ...$relations): self
    {
        foreach ($relations as $relation) {
            foreach ((array) $relation as $name => $constraint) {
                if (is_int($name)) {
                    $this->eagerLoad[(string) $constraint] = null;
                } else {
                    $this->eagerLoad[$name] = $constraint;
                }
            }
        }

        return $this;
    }

    public function withTrashed(): self
    {
        $this->trashed = 'with';

        return $this;
    }

    public function onlyTrashed(): self
    {
        $this->trashed = 'only';

        return $this;
    }

    /** Local scopes: a model method scopeActive(QueryBuilder $q) is callable as ->active() */
    public function __call(string $method, array $arguments): mixed
    {
        $model = $this->model;
        $scope = 'scope' . ucfirst($method);

        if ($model !== null && method_exists($model, $scope)) {
            $result = (new $model())->{$scope}($this, ...$arguments);

            return $result instanceof self ? $result : $this;
        }

        throw new \BadMethodCallException("Method QueryBuilder::$method() does not exist.");
    }

    // ------------------------------------------------------------------ reading

    public function get(): Collection
    {
        $rows = $this->db->select($this->toSql(), $this->getBindings());

        if ($this->model === null) {
            return new Collection($rows);
        }

        $model = $this->model;
        $models = array_map(static fn (array $row) => $model::newFromRow($row), $rows);

        if ($models !== [] && $this->eagerLoad !== []) {
            $model::eagerLoadRelations($models, $this->eagerLoad);
        }

        return new Collection($models);
    }

    /** @return Model|array<string, mixed>|null */
    public function first(): mixed
    {
        $clone = clone $this;
        $clone->limit = 1;

        return $clone->get()->first();
    }

    /** @return Model|array<string, mixed> */
    public function firstOrFail(): mixed
    {
        return $this->first() ?? throw new HttpException(404);
    }

    /** @return Model|array<string, mixed>|null */
    public function find(int|string $id, string $key = 'id'): mixed
    {
        $key = $this->model !== null ? $this->model::primaryKey() : $key;

        return (clone $this)->where($this->table . '.' . $key, $id)->first();
    }

    /** Single column value from the first row. */
    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();
        $name = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

        return $row instanceof Model ? $row->{$name} : ($row[$name] ?? null);
    }

    public function pluck(string $column, ?string $key = null): Collection
    {
        $clone = clone $this;
        $clone->model = null;
        $clone->eagerLoad = [];
        $columns = $key === null ? [$column] : [$column, $key];
        $clone->select(...$columns);

        return $clone->get()->pluck($this->baseName($column), $key === null ? null : $this->baseName($key));
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): int|float
    {
        return $this->aggregate('SUM', $column) + 0;
    }

    public function avg(string $column): ?float
    {
        $value = $this->aggregate('AVG', $column);

        return $value === null ? null : (float) $value;
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Paginator
    {
        $page = max(1, $page ?? (int) (request()?->query($pageName) ?? 1));
        $total = $this->count();

        $items = (clone $this)->forPage($page, $perPage)->get();

        return new Paginator($items, $total, $perPage, $page, $pageName);
    }

    /** Process large tables in pieces without loading everything into memory. */
    public function chunk(int $size, callable $callback): void
    {
        $page = 1;
        do {
            $results = (clone $this)->forPage($page, $size)->get();
            if ($results->isEmpty() || $callback($results, $page) === false) {
                return;
            }
            $page++;
        } while ($results->count() === $size);
    }

    // ------------------------------------------------------------------ writing

    /**
     * Insert one row (assoc array) or many rows (list of assoc arrays).
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $values
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $rows = array_is_list($values) ? $values : [$values];
        $columns = array_keys($rows[0]);
        $wrapped = implode(', ', array_map([$this->db, 'quoteIdentifier'], $columns));
        $rowSql = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $bindings[] = $this->normaliseValue($row[$column] ?? null);
            }
        }

        // Oracle and Firebird have no multi-row VALUES: insert one row at a time in a transaction.
        if (count($rows) > 1 && in_array($this->db->driver(), ['oci', 'firebird'], true)) {
            $single = 'INSERT INTO ' . $this->wrapTable($this->table) . " ($wrapped) VALUES $rowSql";
            $this->db->transaction(function () use ($single, $bindings, $columns): void {
                foreach (array_chunk($bindings, count($columns)) as $rowBindings) {
                    $this->db->execute($single, $rowBindings);
                }
            });

            return true;
        }

        $sql = 'INSERT INTO ' . $this->wrapTable($this->table) . " ($wrapped) VALUES "
            . implode(', ', array_fill(0, count($rows), $rowSql));

        $this->db->execute($sql, $bindings);

        return true;
    }

    /**
     * Insert one row and return its new ID (works on every database).
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $key = 'id'): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = implode(', ', array_map([$this->db, 'quoteIdentifier'], array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql = $this->db->grammar()->compileInsertGetId($this->wrapTable($this->table), $columns, $placeholders, $key);

        return $this->db->insertGetId($sql, array_values(array_map([$this, 'normaliseValue'], $values)));
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        $this->guardAgainstMissingWhere('update');

        if ($values === []) {
            return 0;
        }

        $sets = implode(', ', array_map(fn ($col) => $this->db->quoteIdentifier($col) . ' = ?', array_keys($values)));
        [$where, $whereBindings] = $this->compileWhereClauses();
        $sql = 'UPDATE ' . $this->wrapTable($this->table) . " SET $sets" . ($where !== '' ? ' WHERE ' . $where : '');

        return $this->db->execute($sql, [...array_map([$this, 'normaliseValue'], array_values($values)), ...$whereBindings]);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->guardAgainstMissingWhere('update');
        $col = $this->db->quoteIdentifier($column);
        $sets = ["$col = $col + ?"];
        $bindings = [$amount];
        foreach ($extra as $key => $value) {
            $sets[] = $this->db->quoteIdentifier((string) $key) . ' = ?';
            $bindings[] = $this->normaliseValue($value);
        }
        [$where, $whereBindings] = $this->compileWhereClauses();

        return $this->db->execute(
            'UPDATE ' . $this->wrapTable($this->table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            [...$bindings, ...$whereBindings],
        );
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    /** Deletes matching rows (or soft-deletes them when the model uses SoftDeletes). */
    public function delete(): int
    {
        $this->guardAgainstMissingWhere('delete');

        if ($this->usesSoftDeletes() && $this->trashed !== 'force') {
            return $this->update(['deleted_at' => date('Y-m-d H:i:s')]);
        }

        [$where, $bindings] = $this->compileWhereClauses();

        return $this->db->execute('DELETE FROM ' . $this->wrapTable($this->table) . ' WHERE ' . $where, $bindings);
    }

    public function forceDelete(): int
    {
        $this->trashed = 'force';

        return $this->delete();
    }

    // ------------------------------------------------------------------ compiling

    public function toSql(): string
    {
        $columns = $this->columns === [] ? ['*'] : $this->columns;
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $columns)
            . ' FROM ' . $this->wrapTable($this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        [$where] = $this->compileWhereClauses();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', array_column($this->havings, 0));
        }
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        // LIMIT/OFFSET, OFFSET ... FETCH, etc. — each database's own syntax.
        return $sql . $this->db->grammar()->compileLimitOffset($this->limit, $this->offset, $this->orders !== []);
    }

    /** @return array<int, mixed> */
    public function getBindings(): array
    {
        [, $whereBindings] = $this->compileWhereClauses();
        $havingBindings = array_merge([], ...array_column($this->havings, 1));

        return [...$this->selectBindings, ...$whereBindings, ...$havingBindings];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function compileWhereClauses(bool $applySoftDeletes = true): array
    {
        $sql = '';
        $bindings = [];
        foreach ($this->wheres as $i => [$boolean, $clause, $clauseBindings]) {
            $sql .= ($i === 0 ? '' : " $boolean ") . $clause;
            array_push($bindings, ...$clauseBindings);
        }

        if ($applySoftDeletes && $this->usesSoftDeletes() && in_array($this->trashed, ['exclude', 'only'], true)) {
            $filter = $this->db->quoteIdentifier($this->table . '.deleted_at')
                . ($this->trashed === 'only' ? ' IS NOT NULL' : ' IS NULL');
            // Parentheses keep "a OR b" from escaping the soft-delete filter.
            $sql = $sql === '' ? $filter : '(' . $sql . ') AND ' . $filter;
        }

        return [$sql, $bindings];
    }

    private function aggregate(string $function, string $column): mixed
    {
        $clone = clone $this;
        $clone->orders = [];
        $clone->limit = null;
        $clone->offset = null;
        $clone->eagerLoad = [];
        $clone->columns = [];
        $clone->selectBindings = [];
        $expr = $column === '*' ? '*' : $this->db->quoteIdentifier($column);
        $clone->selectRaw("$function($expr) AS aggregate");

        if ($clone->groups !== []) {
            $sql = 'SELECT COUNT(*) AS aggregate FROM (' . $clone->toSql() . ') grouped_rows';

            return $this->db->selectOne($sql, $clone->getBindings())['aggregate'] ?? null;
        }

        return $this->db->selectOne($clone->toSql(), $clone->getBindings())['aggregate'] ?? null;
    }

    private function usesSoftDeletes(): bool
    {
        return $this->model !== null && $this->model::usesSoftDeletes();
    }

    private function wrapTable(string $table): string
    {
        if (preg_match('/^(\w+)\s+as\s+(\w+)$/i', $table, $m)) {
            return $this->db->grammar()->tableAlias($m[1], $m[2]);
        }

        return $this->db->quoteIdentifier($table);
    }

    private function wrapColumn(string $column): string
    {
        if (preg_match('/^([\w.*]+)\s+as\s+(\w+)$/i', $column, $m)) {
            return $this->db->quoteIdentifier($m[1]) . ' AS ' . $this->db->quoteIdentifier($m[2]);
        }
        if (str_ends_with($column, '.*')) {
            return $this->db->quoteIdentifier(substr($column, 0, -2)) . '.*';
        }

        return $this->db->quoteIdentifier($column);
    }

    private function baseName(string $column): string
    {
        if (preg_match('/\s+as\s+(\w+)$/i', $column, $m)) {
            return $m[1];
        }

        return str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
    }

    private function checkOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator [$operator].");
        }

        return $operator;
    }

    private function normaliseValue(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => (int) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => json_encode($value),
            default => $value,
        };
    }

    private function guardAgainstMissingWhere(string $action): void
    {
        if ($this->wheres === []) {
            throw new LogicException("Refusing to $action every row in [$this->table] without a where() clause.");
        }
    }
}
