<?php

declare(strict_types=1);

namespace Core;

use Throwable;

/**
 * The application: a service container that boots config, error handling and providers,
 * then turns each Request into a Response.
 */
final class App extends Container
{
    public const VERSION = '1.0.0';

    private static ?self $instance = null;

    public readonly Router $router;

    public readonly View $view;

    private ?Request $request = null;

    /** @var ServiceProvider[] */
    private array $providers = [];

    public function __construct(public readonly string $basePath)
    {
        self::$instance = $this;
        $this->bindInstance(self::class, $this);
        $this->bindInstance(Container::class, $this);

        Env::load($basePath . '/.env');
        Config::load($basePath . '/config');

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
        ErrorHandler::register((bool) Config::get('app.debug', false), $basePath . '/storage/logs');

        $this->router = new Router($this);
        $this->view = new View($basePath . '/resources/views', $basePath . '/storage/framework/views');
        $this->bindInstance(Router::class, $this->router);
        $this->bindInstance(View::class, $this->view);
        $this->singleton(Database::class, static fn () => Database::instance());

        foreach ((array) Config::get('app.providers', []) as $provider) {
            $this->providers[] = $instance = new $provider($this);
            $instance->register();
        }
        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        $this->loadRoutes();
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new \LogicException('The application has not been created yet.');
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function environment(string ...$environments): string|bool
    {
        $env = (string) Config::get('app.env', 'production');

        return $environments === [] ? $env : in_array($env, $environments, true);
    }

    public function isDownForMaintenance(): bool
    {
        return is_file($this->maintenanceFile());
    }

    public function maintenanceFile(): string
    {
        return $this->basePath . '/storage/framework/down';
    }

    public function run(?Request $request = null): void
    {
        $this->handle($request ?? Request::capture())->send();

        if ($this->afterResponse !== []) {
            // Deliver the response to the visitor first, then do the deferred work.
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();            // PHP-FPM: the connection is closed now
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();                             // Apache mod_php: sent, but the connection stays open
            }
        }

        $this->terminate();
    }

    /** @var array<int, callable> */
    private array $afterResponse = [];

    /**
     * Run $callback after the response has been sent (e.g. slow emails). Outside a web request — console,
     * queue worker — it runs immediately. Exceptions are reported, never shown to the visitor.
     */
    public function afterResponse(callable $callback): void
    {
        if ($this->request === null) {
            $callback();

            return;
        }

        $this->afterResponse[] = $callback;
    }

    /** Runs the after-response callbacks (called by run(), and by the test client after each request). */
    public function terminate(): void
    {
        while ($this->afterResponse !== []) {
            $callback = array_shift($this->afterResponse);
            try {
                $callback();
            } catch (Throwable $e) {
                ErrorHandler::report($e);
            }
        }
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->bindInstance(Request::class, $request);
        // API routes are stateless: no session, no session cookie — they authenticate with tokens only.
        if ($request->path() === '/api' || str_starts_with($request->path(), '/api/')) {
            Session::startStateless();
        } else {
            Session::start((array) Config::get('session', []), $request->isSecure());
        }
        Lang::setLocale((string) Session::get('locale', Config::get('app.locale', 'en')));

        try {
            Firewall::inspect($request);
            $this->checkMaintenanceMode($request);
            $response = $this->router->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->renderException($e, $request);
        }

        // Remember the last page viewed so back() works after a failed form submission.
        if ($request->method() === 'GET' && $response->status() === 200 && !$request->wantsJson()) {
            Session::set('_previous_url', $request->pathWithQuery());
        }

        Session::endRequest();

        return $response;
    }

    private function loadRoutes(): void
    {
        $router = $this->router;

        if (is_file($this->basePath . '/routes/web.php')) {
            $router->group(['middleware' => ['web']], function (Router $router): void {
                require $this->basePath . '/routes/web.php';
            });
        }

        if (is_file($this->basePath . '/routes/api.php')) {
            $router->group(['prefix' => '/api', 'middleware' => ['api']], function (Router $router): void {
                require $this->basePath . '/routes/api.php';
            });
        }
    }

    /** "php console down --secret=xyz": visit /xyz once to get a bypass cookie. */
    private function checkMaintenanceMode(Request $request): void
    {
        if (!$this->isDownForMaintenance()) {
            return;
        }

        $data = json_decode((string) file_get_contents($this->maintenanceFile()), true) ?: [];
        $secret = (string) ($data['secret'] ?? '');

        if ($secret !== '' && hash_equals('/' . $secret, $request->path())) {
            Response::queueCookie('maintenance_bypass', hash_hmac('sha256', $secret, Crypt::key()), 720);
            throw new RedirectException(Response::redirect('/'));
        }

        $cookie = (string) $request->cookie('maintenance_bypass', '');
        if ($secret !== '' && $cookie !== '' && hash_equals(hash_hmac('sha256', $secret, Crypt::key()), $cookie)) {
            return;
        }

        throw new HttpException(503, (string) ($data['message'] ?? 'We are doing some maintenance. Please check back soon.'), [
            'Retry-After' => (string) ($data['retry'] ?? 60),
        ]);
    }

    private function renderException(Throwable $e, Request $request): Response
    {
        if ($e instanceof RedirectException) {
            return $e->response;
        }

        if ($e instanceof ValidationException) {
            if ($request->wantsJson()) {
                return Response::json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }

            $input = $request->except(['password', 'password_confirmation', 'current_password', 'email_current_password', 'password_delete', 'code', '_token', '_method']);

            return back()->withErrors($e->errors())->withInput($input);
        }

        $status = $e instanceof HttpException ? $e->status() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];

        $errorId = null;
        if ($status >= 500 && $status !== 503) {
            $errorId = ErrorHandler::report($e);
        }

        $debug = ErrorHandler::debug();
        // Real exception messages can leak internals (SQL, paths), so production shows a safe text.
        $message = $e instanceof HttpException || $debug ? $e->getMessage() : HttpException::reason($status);

        if ($request->wantsJson()) {
            $body = ['message' => $message, 'status' => $status];
            if ($errorId !== null) {
                $body['error_id'] = $errorId;
            }
            if ($debug && $status >= 500 && !$e instanceof HttpException) {
                $solution = Exceptions\SolutionProvider::for($e);
                $body += ['exception' => $e::class, 'file' => $e->getFile() . ':' . $e->getLine(), 'fix' => $solution['fix'] ?? null];
            }
            $response = Response::json($body, $status);
        } elseif ($debug && $status >= 500 && !$e instanceof HttpException) {
            $response = Response::html(ErrorHandler::debugPage($e, $request, $errorId), $status);
        } else {
            $response = $this->errorPage($status, $message, $errorId);
        }

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    private function errorPage(int $status, string $message, ?string $errorId): Response
    {
        $view = $this->view->exists("errors.$status") ? "errors.$status" : 'errors.error';

        try {
            return Response::html($this->view->render($view, ['status' => $status, 'message' => $message, 'errorId' => $errorId]), $status);
        } catch (Throwable $e) {
            // The error page itself failed (e.g. a broken layout): fall back to a self-contained page.
            ErrorHandler::report($e);

            return Response::html(
                '<!doctype html><meta charset="utf-8"><title>' . $status . '</title><style nonce="' . e(Csp::nonce()) . '">'
                . 'body{font:16px system-ui;text-align:center;padding:80px 16px}h1{font-size:56px;margin:0;color:#dc2626}</style><body>'
                . '<h1>' . $status . '</h1><p>' . e(HttpException::reason($status)) . '</p>'
                . ($errorId ? '<small>Error reference: ' . e($errorId) . '</small>' : '') . '</body>',
                $status,
            );
        }
    }
}
