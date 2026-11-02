# Cache

Store results of slow work (heavy queries, API calls) for a while. The file cache lives in `storage/framework/cache`.

```php
use Core\Cache;

$stats = Cache::remember('dashboard.stats', 600, function () {      // 10 minutes
    return ['orders' => DB::table('orders')->count(), 'revenue' => DB::table('orders')->sum('amount_minor')];
});

Cache::put('rates', $rates, 3600);
Cache::forever('settings', $settings);
Cache::get('rates');
Cache::get('rates', []);                    // default
Cache::has('rates');
Cache::add('lock:import', true, 300);       // only if missing (simple locks)
Cache::increment('visits');
Cache::pull('flash-key');                   // read and delete
Cache::forget('rates');
Cache::flush();                             // everything
```

Helper: `cache('key')`, `cache(['key' => $value], 60)`.

```bash
php console cache:clear
php console optimize:clear      # cache + compiled views + rate limits
```

> **Tip:** Include something that changes in the key when the data depends on it, e.g. `"user.{$id}.orders"`, and `Cache::forget()` it when the data is updated.

## Stores and safety

```ini
CACHE_STORE=file        # or database (table "cache") when several servers must share it
```

Every entry is signed with an HMAC of `APP_KEY`; a modified cache file or row is ignored. Values are restored **without creating objects** unless their classes are listed in `config/cache.php` → `allowed_classes`, so cache arrays and scalars (IDs, counts, strings, arrays of those). Expired entries are pruned hourly by the scheduler.
