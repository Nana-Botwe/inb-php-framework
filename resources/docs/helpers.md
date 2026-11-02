# Helpers & utilities

## Global functions

| Function | Purpose |
|---|---|
| `app()`, `app(Service::class)` | the application / resolve a class from the container |
| `config('key')`, `env('KEY')` | configuration |
| `request()`, `auth()`, `session('key')` | current request, user, session/flash value |
| `view()`, `redirect()`, `back()`, `response()`, `json()` | responses |
| `abort(404)`, `abort_if()`, `abort_unless()` | stop with an HTTP error |
| `url('/path')`, `route('name', [...])`, `asset('assets/app.css')` | URLs (sub-folder aware; `asset()` adds a cache-busting version) |
| `e($value)` | escape HTML |
| `old('field')`, `errors()`, `error('field')`, `csrf_field()`, `csrf_token()`, `method_field('PUT')`, `csp_nonce()` | forms |
| `is_active('dashboard')` | is the current page this path? |
| `__('key')`, `trans()`, `trans_choice()` | translations |
| `collect([...])` | collection |
| `cache()`, `encrypt()`, `decrypt()`, `bcrypt()` | cache & crypto |
| `event()`, `dispatch()`, `logger()`, `report($e)` | events, jobs, logging |
| `now()`, `today()` | `DateTimeImmutable` |
| `blank()`, `filled()`, `value()`, `tap()`, `retry(3, fn () => ...)`, `data_get($array, 'a.b')`, `class_basename()` | utilities |
| `fake()` | fake data for seeders/tests |
| `base_path()`, `app_path()`, `config_path()`, `database_path()`, `public_path()`, `resource_path()`, `storage_path()` | paths |
| `dump()`, `dd()` | debugging |

## Strings — `Core\Support\Str`

```php
Str::slug('Hello World!');          // "hello-world"
Str::random(32);                    // cryptographically secure
Str::uuid();
Str::limit($text, 100);             Str::words($text, 20);
Str::studly('user_name');           // "UserName"
Str::camel('user_name');            // "userName"
Str::snake('UserName');             // "user_name"
Str::kebab('UserName');             // "user-name"
Str::title('ama mensah');           // "Ama Mensah"
Str::plural('category');            // "categories"
Str::contains($s, ['a', 'b']);      Str::startsWith($s, 'x');      Str::endsWith($s, 'y');
Str::before('a@b.com', '@');        Str::after('a@b.com', '@');
Str::is('admin/*', $path);
Str::mask('0241234567', '*', 3, 4); // "024****567"
```

## Arrays — `Core\Support\Arr`

```php
Arr::get($data, 'user.address.city', 'Accra');
Arr::set($data, 'user.name', 'Ama');
Arr::has($data, 'user.email');
Arr::only($data, ['name', 'email']);   Arr::except($data, ['password']);
Arr::flatten($nested);   Arr::wrap($value);   Arr::first($items, fn ($x) => $x > 2);
```

## Service container

Classes are created with their dependencies injected automatically. Bind your own services in `AppServiceProvider::register()`:

```php
$this->app->singleton(ExchangeRates::class, fn () => new ExchangeRates(config('services.rates_key')));
$this->app->bind(ReportExporter::class, CsvExporter::class);   // interface -> implementation

$rates = app(ExchangeRates::class);
```

Then type-hint them in controllers, jobs, middleware or commands.

## Service providers

```bash
php console make:provider BillingServiceProvider
```

Add it to `providers` in `config/app.php`. `register()` binds things; `boot()` runs after all providers are registered (listen to events, share view data).

## HTTP client

For calling other APIs (TLS always verified, timeouts, HTTPS required for credentials):

```php
use Core\Http\Client;

$response = Client::make()->withToken($key)->asJson()->timeout(15)->post('https://api.example.com/orders', ['id' => 5]);
$response->ok();  $response->status();  $response->json('data.id');  $response->body();
Client::make()->withBasicAuth($user, $pass)->get('https://api.example.com/me', ['expand' => 'all']);
```
