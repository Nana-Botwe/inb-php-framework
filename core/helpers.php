<?php

declare(strict_types=1);

use Core\App;
use Core\Auth;
use Core\Cache;
use Core\Config;
use Core\Crypt;
use Core\Csp;
use Core\Csrf;
use Core\Env;
use Core\ErrorHandler;
use Core\Event;
use Core\Hash;
use Core\HttpException;
use Core\Lang;
use Core\Log;
use Core\Model;
use Core\Queue\Job;
use Core\Queue\Queue;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Support\Arr;
use Core\Support\Collection;
use Core\Support\Fake;
use Core\Support\HtmlString;

/*
|--------------------------------------------------------------------------
| Global helper functions available everywhere (controllers, views, routes)
|--------------------------------------------------------------------------
*/

/** The app, or a class resolved from the container: app(PaymentService::class) */
function app(?string $abstract = null, array $parameters = []): mixed
{
    return $abstract === null ? App::instance() : App::instance()->make($abstract, $parameters);
}

function request(): ?Request
{
    return App::instance()->request();
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

// ------------------------------------------------------------------ paths

function base_path(string $path = ''): string
{
    return App::instance()->basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function app_path(string $path = ''): string
{
    return base_path('app' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function config_path(string $path = ''): string
{
    return base_path('config' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function database_path(string $path = ''): string
{
    return base_path('database' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function public_path(string $path = ''): string
{
    return base_path('public' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function resource_path(string $path = ''): string
{
    return base_path('resources' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function storage_path(string $path = ''): string
{
    return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

// ------------------------------------------------------------------ output & URLs

/** Escape output for HTML. {{ }} in Blade does this automatically. */
function e(mixed $value): string
{
    if ($value instanceof HtmlString) {
        return $value->toHtml();
    }
    if ($value instanceof BackedEnum) {
        $value = $value->value;
    }
    if ($value instanceof DateTimeInterface) {
        $value = $value->format('Y-m-d H:i:s');
    }

    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** App URL for a path, working from any sub-folder: url('/login') -> "/MVC%20Models/login" */
function url(string $path = '/'): string
{
    if (preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }

    $request = App::instance()->request();
    $base = $request !== null
        ? $request->basePath()
        : rtrim(rawurldecode((string) parse_url((string) config('app.url', ''), PHP_URL_PATH)), '/');

    $encodedBase = implode('/', array_map('rawurlencode', explode('/', $base)));
    $path = '/' . ltrim($path, '/');

    return ($encodedBase === '' && $path === '/') ? '/' : $encodedBase . $path;
}

/** URL to a file in public/ with a cache-busting version: asset('css/app.css') */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = public_path($path);
    $version = is_file($file) ? '?v=' . substr(md5((string) filemtime($file)), 0, 8) : '';

    return url($path) . $version;
}

/** URL for a named route: route('users.show', ['user' => 5]) */
function route(string $name, array $params = [], bool $absolute = false): string
{
    $path = App::instance()->router->url($name, $params);

    return $absolute ? Core\URL::to($path) : url($path);
}

function view(string $view, array $data = [], int $status = 200): Response
{
    return Response::html(App::instance()->view->render($view, $data), $status);
}

function redirect(string $to = '/', int $status = 302): Response
{
    return Response::redirect($to, $status);
}

/** Redirect to the previous page (falls back to the home page). */
function back(): Response
{
    $previous = Session::get('_previous_url');

    return Response::redirect(Response::isSafePath($previous) ? (string) $previous : '/');
}

function response(string $content = '', int $status = 200, array $headers = []): Response
{
    return new Response($content, $status, $headers);
}

function json(mixed $data, int $status = 200): Response
{
    return Response::json($data, $status);
}

/** Stop the request with an HTTP error: abort(404), abort(403, 'Admins only'). */
function abort(int $status, string $message = ''): never
{
    throw new HttpException($status, $message);
}

function abort_if(bool $condition, int $status, string $message = ''): void
{
    if ($condition) {
        abort($status, $message);
    }
}

function abort_unless(bool $condition, int $status, string $message = ''): void
{
    if (!$condition) {
        abort($status, $message);
    }
}

// ------------------------------------------------------------------ session, forms, auth

function session(string $key, mixed $default = null): mixed
{
    return Session::getFlash($key) ?? Session::get($key, $default);
}

/** Previously submitted form value (after a validation error): value="{{ old('email') }}" */
function old(string $key, mixed $default = ''): mixed
{
    $input = Session::getFlash('_old_input', []);

    return is_array($input) && array_key_exists($key, $input) ? $input[$key] : $default;
}

/** @return array<string, string[]> All validation errors from the last request. */
function errors(): array
{
    $errors = Session::getFlash('errors', []);

    return is_array($errors) ? $errors : [];
}

/** First validation error message for a field, or null. */
function error(string $field): ?string
{
    return errors()[$field][0] ?? null;
}

function csrf_token(): string
{
    return Csrf::token();
}

/** Hidden input to put inside every <form method="post"> (Blade: @csrf). */
function csrf_field(): HtmlString
{
    return new HtmlString('<input type="hidden" name="_token" value="' . e(Csrf::token()) . '" autocomplete="off">');
}

/** Lets an HTML form send PUT/PATCH/DELETE (Blade: @method('DELETE')). */
function method_field(string $method): HtmlString
{
    return new HtmlString('<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">');
}

/** Nonce for inline scripts/styles allowed by the Content-Security-Policy: <script nonce="@nonce"> */
function csp_nonce(): string
{
    return Csp::nonce();
}

function auth(): ?Model
{
    return Auth::user();
}

/** True when the current path matches, e.g. for active nav links: is_active('about') or is_active('admin/*') */
function is_active(string $pattern): bool
{
    return App::instance()->request()?->is($pattern === '/' ? '' : $pattern) ?? false;
}

// ------------------------------------------------------------------ data & utilities

function collect(mixed $items = []): Collection
{
    return Collection::make($items);
}

function data_get(mixed $target, string|int|null $key, mixed $default = null): mixed
{
    return Arr::get($target, $key, $default);
}

function blank(mixed $value): bool
{
    return $value === null || $value === [] || (is_string($value) && trim($value) === '')
        || ($value instanceof Countable && count($value) === 0);
}

function filled(mixed $value): bool
{
    return !blank($value);
}

function value(mixed $value, mixed ...$args): mixed
{
    return $value instanceof Closure ? $value(...$args) : $value;
}

function tap(mixed $value, callable $callback): mixed
{
    $callback($value);

    return $value;
}

/** Retry an operation (e.g. a flaky API) a few times before giving up. */
function retry(int $times, callable $callback, int $sleepMilliseconds = 0): mixed
{
    $attempt = 0;
    beginning:
    $attempt++;
    try {
        return $callback($attempt);
    } catch (Throwable $e) {
        if ($attempt >= $times) {
            throw $e;
        }
        if ($sleepMilliseconds > 0) {
            usleep($sleepMilliseconds * 1000);
        }
        goto beginning;
    }
}

function class_basename(string|object $class): string
{
    $class = is_object($class) ? $class::class : $class;

    return basename(str_replace('\\', '/', $class));
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable();
}

function today(): DateTimeImmutable
{
    return new DateTimeImmutable('today');
}

/** cache('key') reads; cache(['key' => $value], 60) writes. */
function cache(string|array|null $key = null, mixed $default = null): mixed
{
    if (is_array($key)) {
        foreach ($key as $k => $v) {
            Cache::put((string) $k, $v, is_int($default) ? $default : null);
        }

        return true;
    }

    return $key === null ? null : Cache::get($key, $default);
}

function encrypt(mixed $value): string
{
    return Crypt::encrypt($value);
}

function decrypt(string $payload): mixed
{
    return Crypt::decrypt($payload);
}

function bcrypt(string $value): string
{
    return Hash::make($value);
}

/** Translate: __('auth.failed'), __('Welcome :name', ['name' => $user->name]) */
function __(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

function trans(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

function trans_choice(string $key, int $count, array $replace = []): string
{
    return Lang::choice($key, $count, $replace);
}

function logger(string $message, array $context = []): void
{
    Log::info($message, $context);
}

/** Log an exception without stopping the request. Returns the error ID. */
function report(Throwable $e): string
{
    return ErrorHandler::report($e);
}

/** @return array<int, mixed> */
function event(object|string $event, array $payload = []): array
{
    return Event::dispatch($event, $payload);
}

/** Queue a background job (runs immediately with QUEUE_CONNECTION=sync). */
function dispatch(Job $job): void
{
    Queue::push($job);
}

function fake(): Fake
{
    static $fake = null;

    return $fake ??= new Fake();
}

function dump(mixed ...$values): void
{
    foreach ($values as $value) {
        echo PHP_SAPI === 'cli' ? print_r($value, true) . PHP_EOL : '<pre>' . e(print_r($value, true)) . '</pre>';
    }
}

/** Dump and die (debugging only). */
function dd(mixed ...$values): never
{
    dump(...$values);
    exit(1);
}
