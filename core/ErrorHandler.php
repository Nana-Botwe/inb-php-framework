<?php

declare(strict_types=1);

namespace Core;

use Core\Exceptions\SolutionProvider;
use ErrorException;
use Throwable;

/**
 * Error handling for every layer.
 *
 * - PHP warnings/notices become exceptions (nothing fails silently).
 * - Every error gets a unique ID and is logged to storage/logs/app-YYYY-MM-DD.log with request context.
 * - APP_DEBUG=true: a styled debug page with the message, a suggested fix, the source code around the
 *   error, a code view for every stack frame, the request (secrets masked) and environment details.
 * - APP_DEBUG=false: a friendly page showing only the error ID, so visitors never see internals
 *   but you can find the exact log entry.
 * - CLI: coloured, readable output with the fix.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    private static string $logDirectory = '';

    private static ?string $lastId = null;

    private const SENSITIVE = ['password', 'password_confirmation', 'current_password', '_token', 'token', 'secret',
        'api_key', 'apikey', 'card', 'cvv', 'pin', 'otp', 'authorization', 'cookie', 'x-csrf-token', 'php_auth_pw'];

    public static function register(bool $debug, string $logDirectory): void
    {
        self::$debug = $debug;
        self::$logDirectory = $logDirectory;

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        // Never put function arguments (passwords, keys) into stack traces or logs.
        ini_set('zend.exception_ignore_args', '1');

        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $level)) {
                return false;
            }
            if (in_array($level, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                self::writeLog('DEPRECATED', "$message in $file:$line");

                return true;
            }
            throw new ErrorException($message, 0, $level, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::report($e);
            self::renderFallback($e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $e = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
                self::report($e);
                self::renderFallback($e);
            }
        });
    }

    public static function debug(): bool
    {
        return self::$debug;
    }

    public static function lastErrorId(): ?string
    {
        return self::$lastId;
    }

    /** Log the exception; returns its error ID (e.g. "ERR-7F3A9C21"). */
    public static function report(Throwable $e): string
    {
        $id = 'ERR-' . strtoupper(bin2hex(random_bytes(4)));
        self::$lastId = $id;

        $request = null;
        try {
            $request = App::instance()->request();
        } catch (Throwable) {
        }

        $context = $request === null ? '' : sprintf(" [%s %s ip=%s user=%s]", $request->method(), $request->path(), $request->ip(), (string) ($_SESSION['_auth_id'] ?? 'guest'));
        $chain = '';
        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $chain .= "\nCaused by: " . $previous::class . ': ' . $previous->getMessage() . ' in ' . $previous->getFile() . ':' . $previous->getLine();
        }

        self::writeLog('ERROR', sprintf("%s%s %s: %s in %s:%d%s\n%s", $id, $context, $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $chain, $e->getTraceAsString()));
        self::alert($e, $id);

        return $id;
    }

    private static bool $alerting = false;

    /** Email LOG_ALERT_EMAIL about a server error — at most once per distinct error per throttle window. */
    private static function alert(Throwable $e, string $id): void
    {
        $to = '';
        try {
            $to = (string) Config::get('logging.alert_email', '');
        } catch (Throwable) {
        }

        if ($to === '' || self::$alerting || $e instanceof HttpException) {
            return;
        }

        self::$alerting = true;   // an error while alerting must not trigger another alert
        try {
            $key = 'error-alert:' . sha1($e::class . '|' . $e->getFile() . '|' . $e->getLine());
            $minutes = (int) Config::get('logging.alert_throttle_minutes', 15);
            if (RateLimiter::hit($key, $minutes * 60, failOpen: false) === 1) {
                Mail\Mail::raw($to, '[' . Config::get('app.name', 'App') . '] Server error ' . $id,
                    "A server error happened.\n\nReference: $id\nType: " . $e::class . "\nMessage: " . $e->getMessage()
                    . "\nWhere: " . self::relative($e->getFile()) . ':' . $e->getLine() . "\nTime: " . date('Y-m-d H:i:s T')
                    . "\n\nSearch storage/logs/app-" . date('Y-m-d') . ".log for $id. Further identical errors are muted for $minutes minutes.");
            }
        } catch (Throwable $alertError) {
            self::writeLog('ERROR', 'Could not send error alert: ' . $alertError->getMessage());
        } finally {
            self::$alerting = false;
        }
    }

    private static function writeLog(string $level, string $message): void
    {
        if (self::$logDirectory === '') {
            return;
        }
        if (!is_dir(self::$logDirectory)) {
            @mkdir(self::$logDirectory, 0775, true);
        }
        @file_put_contents(self::$logDirectory . '/app-' . date('Y-m-d') . '.log', '[' . date('Y-m-d H:i:s') . "] $level $message\n\n", FILE_APPEND | LOCK_EX);
    }

    // ------------------------------------------------------------------ debug page

    public static function debugPage(Throwable $e, ?Request $request = null, ?string $errorId = null): string
    {
        $h = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $solution = SolutionProvider::for($e);
        $nonce = '';
        try {
            $nonce = Csp::nonce();
        } catch (Throwable) {
        }

        $frames = self::frames($e);
        $framesHtml = '';
        foreach ($frames as $i => $frame) {
            $label = $h($frame['call']);
            $location = $frame['file'] !== '' ? $h(self::relative($frame['file'])) . ':' . $frame['line'] : '[internal]';
            $vendor = $frame['app'] ? '' : ' vendor';
            $framesHtml .= '<details class="frame' . $vendor . '"' . ($i === 0 ? ' open' : '') . '><summary><span class="num">' . ($i + 1) . '</span>'
                . '<span class="call">' . $label . '</span><span class="loc">' . $location . '</span></summary>'
                . ($frame['file'] !== '' ? self::snippet($frame['file'], $frame['line'], $h) : '') . '</details>';
        }

        $previous = '';
        for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
            $previous .= '<div class="prev"><b>' . $h($p::class) . '</b>: ' . $h($p->getMessage()) . '<br><code>' . $h(self::relative($p->getFile())) . ':' . $p->getLine() . '</code></div>';
        }

        $requestHtml = '';
        if ($request !== null) {
            $rows = [
                'Method' => $request->method(),
                'URL' => $request->pathWithQuery(),
                'IP' => $request->ip(),
                'User agent' => $request->userAgent(),
                'Signed in user' => (string) ($_SESSION['_auth_id'] ?? 'guest'),
            ];
            $requestHtml .= self::table($rows, $h);

            $headers = [];
            foreach ((array) $_SERVER as $key => $value) {
                if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                    $name = ucwords(strtolower(str_replace('_', '-', substr($key, 5))), '-');
                    $headers[$name] = self::mask($name, $value);
                }
            }
            $requestHtml .= '<h3>Headers</h3>' . self::table($headers, $h);
            $input = [];
            foreach ($request->all() as $key => $value) {
                $input[(string) $key] = self::mask((string) $key, is_scalar($value) ? (string) $value : json_encode($value));
            }
            $requestHtml .= '<h3>Input</h3>' . ($input === [] ? '<p class="muted">No input.</p>' : self::table($input, $h));
        }

        $env = [
            'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'Framework' => 'INB PHP Framework ' . App::VERSION,
            'Environment' => (string) Config::get('app.env', '?'),
            'Database driver' => (static function (): string { try { return Database::instance()->driver(); } catch (Throwable) { return '?'; } })(),
            'Memory peak' => round(memory_get_peak_usage(true) / 1048576, 1) . ' MB',
            'Time' => date('Y-m-d H:i:s T'),
        ];

        $solutionHtml = $solution === null ? '' : '<section class="solution"><div class="badge">Suggested fix</div><h2>'
            . $h($solution['title']) . '</h2><p>' . $h($solution['fix']) . '</p></section>';

        $css = <<<'CSS'
:root{--bg:#f4f5f7;--card:#fff;--text:#1d2433;--muted:#667085;--line:#e4e7ec;--accent:#dc2626;--accent-soft:#fef2f2;--ok:#047857;--ok-soft:#ecfdf5;--code:#0f172a;--code-text:#e2e8f0;--hl:#7f1d1d}
@media (prefers-color-scheme:dark){:root{--bg:#0b0f17;--card:#121826;--text:#e6e8ee;--muted:#98a2b3;--line:#232b3b;--accent-soft:#2a1215;--ok:#34d399;--ok-soft:#0d2a22;--code:#0a0e16}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:24px 16px 64px}
.hero{background:var(--card);border:1px solid var(--line);border-left:6px solid var(--accent);border-radius:14px;padding:24px 26px;box-shadow:0 1px 2px rgba(16,24,40,.06)}
.type{display:inline-block;font:600 12px/1 ui-monospace,Consolas,monospace;color:var(--accent);background:var(--accent-soft);padding:6px 10px;border-radius:999px;margin-bottom:12px}
h1{font-size:22px;margin:0 0 10px;word-break:break-word}.where{font:13px ui-monospace,Consolas,monospace;color:var(--muted)}
.meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.chip{font-size:12px;color:var(--muted);border:1px solid var(--line);border-radius:999px;padding:4px 10px}
.solution{margin-top:16px;background:var(--ok-soft);border:1px solid var(--ok);border-radius:14px;padding:18px 22px}.solution h2{margin:6px 0 4px;font-size:17px;color:var(--ok)}.solution p{margin:0}
.badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ok)}
.card{margin-top:16px;background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
.card>h2{margin:0;padding:14px 20px;font-size:15px;border-bottom:1px solid var(--line)}.card .body{padding:16px 20px}
.frame{border-bottom:1px solid var(--line)}.frame:last-child{border-bottom:0}.frame summary{display:flex;gap:12px;align-items:baseline;padding:10px 20px;cursor:pointer;list-style:none}
.frame summary::-webkit-details-marker{display:none}.frame summary:hover{background:var(--accent-soft)}.frame.vendor summary{opacity:.6}
.num{min-width:26px;color:var(--muted);font-size:12px}.call{font:13px ui-monospace,Consolas,monospace;flex:1;word-break:break-all}.loc{font:12px ui-monospace,Consolas,monospace;color:var(--muted);word-break:break-all}
pre.code{margin:0;background:var(--code);color:var(--code-text);font:13px/1.6 ui-monospace,Consolas,monospace;overflow:auto;padding:10px 0}
pre.code span{display:block;padding:0 16px;white-space:pre}pre.code span.hl{background:var(--hl);color:#fff}pre.code i{display:inline-block;width:48px;color:#64748b;font-style:normal;user-select:none}
table{width:100%;border-collapse:collapse;font-size:13px}td{border-top:1px solid var(--line);padding:7px 10px;vertical-align:top;word-break:break-all}td:first-child{width:200px;color:var(--muted);font-weight:600}
h3{font-size:13px;margin:18px 0 6px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}.muted{color:var(--muted)}
.prev{margin-top:10px;padding:10px 14px;border-left:3px solid var(--line);font-size:14px}.foot{margin-top:18px;font-size:12px;color:var(--muted);text-align:center}
CSS;

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $h($e::class) . ' — ' . $h(Config::get('app.name', 'App')) . '</title>'
            . '<style nonce="' . $h($nonce) . '">' . $css . '</style></head><body><div class="wrap">'
            . '<section class="hero"><span class="type">' . $h($e::class) . '</span><h1>' . $h($e->getMessage() !== '' ? $e->getMessage() : '(no message)') . '</h1>'
            . '<div class="where">' . $h(self::relative($e->getFile())) . ':' . $e->getLine() . '</div>'
            . '<div class="meta">' . ($errorId ? '<span class="chip">' . $h($errorId) . '</span>' : '')
            . '<span class="chip">PHP ' . $h(PHP_VERSION) . '</span><span class="chip">INB PHP Framework ' . $h(App::VERSION) . '</span>'
            . ($request ? '<span class="chip">' . $h($request->method() . ' ' . $request->path()) . '</span>' : '') . '</div>'
            . $previous . '</section>'
            . $solutionHtml
            . '<section class="card"><h2>Stack trace</h2>' . $framesHtml . '</section>'
            . ($requestHtml !== '' ? '<section class="card"><h2>Request</h2><div class="body">' . $requestHtml . '</div></section>' : '')
            . '<section class="card"><h2>Environment</h2><div class="body">' . self::table($env, $h) . '</div></section>'
            . '<p class="foot">You are seeing this because APP_DEBUG=true. Set APP_DEBUG=false on a live server — visitors will then only see a friendly page and the error ID.</p>'
            . '</div></body></html>';
    }

    /** @return array<int, array{file: string, line: int, call: string, app: bool}> */
    private static function frames(Throwable $e): array
    {
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine(), 'call' => $e::class . ' thrown', 'app' => self::isApp($e->getFile())]];

        foreach ($e->getTrace() as $frame) {
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
            $file = (string) ($frame['file'] ?? '');
            $frames[] = ['file' => $file, 'line' => (int) ($frame['line'] ?? 0), 'call' => $call, 'app' => $file !== '' && self::isApp($file)];
        }

        return $frames;
    }

    /** Your own code (app/, routes/, resources/, config/) is highlighted; framework frames are dimmed. */
    private static function isApp(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        return (bool) preg_match('#/(app|routes|resources|config|database|tests)/#', $file) || str_contains($file, '/storage/framework/views/');
    }

    private static function snippet(string $file, int $line, callable $h): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $start = max(1, $line - 7);
        $end = min(count($lines), $line + 7);
        $out = '<pre class="code">';
        for ($i = $start; $i <= $end; $i++) {
            $out .= '<span' . ($i === $line ? ' class="hl"' : '') . '><i>' . $i . '</i>' . $h($lines[$i - 1] ?? '') . '</span>';
        }

        return $out . '</pre>';
    }

    /** @param array<string, string> $rows */
    private static function table(array $rows, callable $h): string
    {
        $html = '<table>';
        foreach ($rows as $key => $value) {
            $html .= '<tr><td>' . $h($key) . '</td><td>' . $h($value) . '</td></tr>';
        }

        return $html . '</table>';
    }

    private static function mask(string $name, mixed $value): string
    {
        foreach (self::SENSITIVE as $word) {
            if (stripos($name, $word) !== false) {
                return '•••••••• (hidden)';
            }
        }

        return (string) $value;
    }

    private static function relative(string $file): string
    {
        try {
            $base = str_replace('\\', '/', App::instance()->basePath) . '/';
        } catch (Throwable) {
            return $file;
        }

        return str_replace($base, '', str_replace('\\', '/', $file));
    }

    // ------------------------------------------------------------------ fallbacks

    /** For CLI commands. */
    public static function renderConsole(Throwable $e): void
    {
        $solution = SolutionProvider::for($e);
        $red = "\033[41;97m";
        $reset = "\033[0m";
        fwrite(STDERR, PHP_EOL . $red . ' ' . $e::class . ' ' . $reset . PHP_EOL . PHP_EOL);
        fwrite(STDERR, '  ' . $e->getMessage() . PHP_EOL . PHP_EOL);
        fwrite(STDERR, "  \033[2mat " . self::relative($e->getFile()) . ':' . $e->getLine() . $reset . PHP_EOL);
        if ($solution !== null) {
            fwrite(STDERR, PHP_EOL . "  \033[32mFix: " . $solution['title'] . $reset . PHP_EOL . '  ' . $solution['fix'] . PHP_EOL);
        }
        fwrite(STDERR, PHP_EOL);
    }

    /** Last-resort output for errors outside the normal request cycle (boot errors, fatal errors). */
    private static function renderFallback(Throwable $e): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (PHP_SAPI === 'cli') {
            self::renderConsole($e);

            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        if (self::$debug) {
            echo self::debugPage($e, null, self::$lastId);

            return;
        }

        $id = htmlspecialchars((string) self::$lastId, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Server Error</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f5f7;color:#1d2433;font:16px/1.6 system-ui,sans-serif}'
            . '.box{max-width:460px;padding:40px 32px;text-align:center}.code{font-size:64px;font-weight:800;color:#dc2626;margin:0}'
            . 'small{display:inline-block;margin-top:14px;color:#667085;border:1px solid #e4e7ec;border-radius:999px;padding:4px 12px}</style></head>'
            . '<body><div class="box"><p class="code">500</p><h1>Something went wrong on our side</h1>'
            . '<p>We have been notified and are looking into it. Please try again in a few minutes.</p>'
            . ($id !== '' ? '<small>Error reference: ' . $id . '</small>' : '') . '</div></body></html>';
    }
}
