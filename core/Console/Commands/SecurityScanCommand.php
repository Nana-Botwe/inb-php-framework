<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config;
use Core\Console\Command;
use Core\Console\Output;
use Core\Http\Client;
use Throwable;

/**
 * Checks a RUNNING site from the outside: secret files must not download, uploads must not execute,
 * security headers must be present. Run it after every deployment (Apache AND Nginx setups).
 *
 *   php console security:scan                         # APP_URL
 *   php console security:scan https://www.example.com
 */
final class SecurityScanCommand extends Command
{
    protected string $signature = 'security:scan {url?}';

    protected string $description = 'Probe a running site for exposed files and missing security headers';

    /** Paths that must never return content. */
    private const SENSITIVE = [
        '/.env', '/.env.example', '/.git/config', '/composer.json', '/composer.lock', '/console', '/README.md',
        '/config/app.php', '/core/App.php', '/app/Models/User.php', '/routes/web.php', '/bootstrap/app.php',
        '/storage/logs/', '/storage/framework/sessions/', '/storage/backups/', '/storage/app/private/',
        '/database/database.sqlite', '/backup/', '/backup/app/core/config.php', '/vendor/', '/public/index.php',
        '/uploads/.htaccess', '/deploy/nginx.conf', '/phpinfo.php', '/info.php',
    ];

    public function handle(): int
    {
        $base = rtrim((string) ($this->argument('url') ?? Config::get('app.url', '')), '/');
        if ($base === '') {
            $this->error('Give a URL or set APP_URL.');

            return self::FAILURE;
        }

        $problems = 0;
        $client = Client::make()->timeout(10)->withHeaders(['Accept' => '*/*']);

        try {
            $home = $client->get($base . '/');
        } catch (Throwable $e) {
            $this->error('Cannot reach ' . $base . ': ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('Scanning ' . $base);
        foreach (self::SENSITIVE as $path) {
            try {
                $status = $client->get($base . $path)->status();
            } catch (Throwable) {
                $status = 0;
            }
            $ok = $status !== 200;
            $problems += $ok ? 0 : 1;
            $this->line(($ok ? Output::color('32', '  ✓ ') : Output::color('31', '  ✗ ')) . str_pad($path, 40) . ' HTTP ' . $status . ($ok ? '' : '  ← EXPOSED'));
        }

        $this->line('');
        $this->line('Headers on the home page:');
        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Content-Security-Policy', 'Referrer-Policy'] as $header) {
            $present = $this->hasHeader($base, $header);
            $problems += $present ? 0 : 1;
            $this->line(($present ? Output::color('32', '  ✓ ') : Output::color('31', '  ✗ ')) . $header);
        }
        if (str_starts_with($base, 'https://') && !$this->hasHeader($base, 'Strict-Transport-Security')) {
            $problems++;
            $this->line(Output::color('31', '  ✗ ') . 'Strict-Transport-Security (HTTPS site without HSTS)');
        }

        $this->line('');
        $problems === 0 ? $this->info('No problems found.') : $this->error("$problems problem(s) found.");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function hasHeader(string $base, string $header): bool
    {
        $context = stream_context_create(['http' => ['method' => 'HEAD', 'ignore_errors' => true, 'timeout' => 10], 'ssl' => ['verify_peer' => true]]);
        $headers = @get_headers($base . '/', true, $context);
        if (!is_array($headers)) {
            return false;
        }

        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strcasecmp($name, $header) === 0) {
                return true;
            }
        }

        return false;
    }
}
