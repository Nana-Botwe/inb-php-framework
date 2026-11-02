# Database & query builder

## Supported databases

The same models, queries and migrations work on every database below — each gets its own SQL dialect automatically (quoting, LIMIT/OFFSET vs OFFSET/FETCH, auto-increment/identity columns, how new IDs are returned, random order, case-insensitive search...).

| Database | `DB_CONNECTION` | PHP extension | Default port |
|---|---|---|---|
| MySQL 5.7+ | `mysql` | `pdo_mysql` | 3306 |
| MariaDB 10.3+ | `mariadb` | `pdo_mysql` | 3306 |
| PostgreSQL 10+ | `pgsql` | `pdo_pgsql` | 5432 |
| SQLite 3.35+ | `sqlite` | `pdo_sqlite` | — |
| SQL Server 2016+ / Azure SQL | `sqlsrv` | `pdo_sqlsrv` (Windows/Linux) or `pdo_dblib` | 1433 |
| Oracle 12c+ | `oracle` | `pdo_oci` + Oracle Instant Client | 1521 |
| Firebird 3+ | `firebird` | `pdo_firebird` | 3050 |
| Anything with ODBC (Db2, Access, Informix...) | `odbc` | `pdo_odbc` + the ODBC driver | — |

```bash
php console db:drivers      # which drivers are installed on this server
php console db:test         # connect and show the server version
php console db:test pgsql   # test another connection
```

To enable a driver on XAMPP, remove the `;` in front of its line in `php.ini` (e.g. `extension=pdo_pgsql`) and restart Apache.

## Connecting

```ini
DB_CONNECTION=mysql       # mysql | mariadb | pgsql | sqlite | sqlsrv | oracle | firebird | odbc
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_shop
DB_USERNAME=my_shop_user
DB_PASSWORD=...
```

Or one URL (handy on cloud hosts):

```ini
DB_URL=postgres://my_user:s%40cret@db.example.com:5432/my_shop?sslmode=require
```

Database-specific settings:

| Database | Extra .env keys |
|---|---|
| SQLite | `DB_DATABASE=database/database.sqlite` (path relative to the project) |
| PostgreSQL | `DB_SCHEMA=public`, `DB_SSLMODE=prefer` (`disable`, `require`, `verify-full`) |
| SQL Server | `DB_ENCRYPT=true`, `DB_TRUST_SERVER_CERTIFICATE=false` |
| Oracle | `DB_SERVICE_NAME=XEPDB1` |
| MySQL | `DB_SOCKET=/var/run/mysqld/mysqld.sock`, `MYSQL_ATTR_SSL_CA=/path/ca.pem` |
| ODBC | `DB_ODBC_DSN="Driver={...};Database=...;Hostname=...;Port=..."` |

Any other PDO DSN can be added in `config/database.php`:

```php
'custom' => ['driver' => 'pgsql', 'dsn' => 'pgsql:host=db;dbname=app;options=--search_path=tenant1', 'username' => '...', 'password' => '...'],
```

The connection opens lazily (only when a query runs), throws exceptions on errors and uses **real prepared statements**. On MySQL/MariaDB, multi-statement queries are disabled and strict SQL mode is on, so bad data is rejected instead of silently truncated. Oracle sessions use `YYYY-MM-DD HH24:MI:SS` dates, and LOB columns are returned as normal strings.

## Several databases at once

Every entry in `config/database.php` → `connections` can be used at the same time:

```php
DB::connection('reporting')->table('daily_sales')->sum('total');
DB::connection('legacy')->select('SELECT * FROM old_orders WHERE id = ?', [$id]);
Schema::connection('reporting')->create('daily_sales', function (Blueprint $table) { ... });

final class LegacyOrder extends Model
{
    protected static ?string $connection = 'legacy';   // this model always uses that database
}
```

Preview the SQL a migration would produce on another database:

```php
print_r(Schema::toSql('posts', fn (Blueprint $t) => $t->id(), 'sqlsrv'));
```

> **Security:** Create a dedicated MySQL user for each app with rights on its own database only. `php console security:check` warns if you connect as `root`.

## Query builder

```php
use Core\DB;

$users = DB::table('users')
    ->select('id', 'name', 'email')
    ->where('role', 'admin')
    ->where('created_at', '>=', '2026-01-01')
    ->orderBy('name')
    ->limit(20)
    ->get();                         // Collection of arrays
```

### Conditions

```php
->where('status', 'paid')                      // =
->where('total', '>', 100)                     // = != <> < > <= >= LIKE "NOT LIKE"
->where(['status' => 'paid', 'currency' => 'GHS'])
->orWhere('status', 'pending')
->where(fn ($q) => $q->where('a', 1)->orWhere('b', 2))   // ( a = 1 OR b = 2 )
->whereIn('id', [1, 2, 3])        ->whereNotIn('id', [4])
->whereNull('deleted_at')         ->whereNotNull('paid_at')
->whereBetween('total', [100, 500])
->whereLike(['name', 'email'], $search)   // safe "contains" search across columns
->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
```

### Ordering, grouping, joins

```php
->orderBy('created_at', 'DESC')   ->orderByDesc('id')   ->latest()   ->oldest()   ->inRandomOrder()
->groupBy('status')->having('total', '>', 10)
->join('users', 'users.id', '=', 'orders.user_id')
->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
->distinct()
->limit(10)->offset(20)           ->forPage(3, 10)
```

### Getting results

```php
->get();            // Collection
->first();          // one row or null
->firstOrFail();    // 404 if none
->find(5);
->value('email');   // single value
->pluck('name');    // Collection of names
->pluck('name', 'id');
->count(); ->sum('total'); ->avg('total'); ->min('total'); ->max('total');
->exists(); ->doesntExist();
->paginate(15);     // see below
->chunk(500, function ($rows) { ... });   // huge tables without running out of memory
```

### Writing

```php
DB::table('logs')->insert(['message' => 'Hi', 'created_at' => date('Y-m-d H:i:s')]);
DB::table('logs')->insert([[...], [...]]);                 // many rows at once
$id = DB::table('logs')->insertGetId([...]);
DB::table('orders')->where('id', 5)->update(['status' => 'paid']);
DB::table('products')->where('id', 7)->increment('views');
DB::table('products')->where('id', 7)->decrement('stock', 2);
DB::table('sessions')->where('expires_at', '<', $now)->delete();
```

> **Note:** `update()` and `delete()` refuse to run without a `where()` — an accidental "update every row" can't happen.

## Pagination

```php
$orders = DB::table('orders')->latest()->paginate(15);   // reads ?page=N
```

```blade
@foreach ($orders as $order) ... @endforeach
{{ $orders->links() }}     {{-- Bootstrap pagination --}}
Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $orders->total() }}
```

As JSON (`return $orders;`) you get `data`, `total`, `per_page`, `current_page`, `last_page`, `next_page_url`, `prev_page_url`.

## Raw SQL

```php
DB::select('SELECT * FROM users WHERE email = ?', [$email]);
DB::selectOne('SELECT COUNT(*) AS c FROM orders WHERE user_id = ?', [$id]);
DB::statement('UPDATE counters SET hits = hits + 1 WHERE id = ?', [1]);
->whereRaw('YEAR(created_at) = ?', [2026])
->selectRaw('COUNT(*) AS total')
```

> **Danger:** In raw SQL, user input goes ONLY in the bindings array (`?`). Never concatenate it into the SQL string.

## Transactions

```php
DB::transaction(function () use ($order) {
    DB::table('orders')->where('id', $order->id)->update(['status' => 'paid']);
    DB::table('stock')->where('product_id', $order->product_id)->decrement('qty');
});   // rolled back automatically if anything throws
```

## Looking at the database

```bash
php console db:show          # tables and row counts
```
