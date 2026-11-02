<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\App;
use Core\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Syntax-check every PHP file (php -l). Used by CI; for deeper checks run PHPStan (see phpstan.neon).
 */
final class LintCommand extends Command
{
    protected string $signature = 'lint';

    protected string $description = 'Check every PHP file for syntax errors';

    public function handle(): int
    {
        $base = App::instance()->basePath;
        $errors = 0;
        $count = 0;

        foreach (['app', 'core', 'config', 'routes', 'database', 'tests', 'bootstrap', 'public', 'lang'] as $dir) {
            if (!is_dir("$base/$dir")) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/$dir", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php') || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $count++;
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);
                if ($code !== 0) {
                    $errors++;
                    $this->error(implode("\n", $output));
                }
            }
        }

        $errors === 0 ? $this->info("No syntax errors in $count files.") : $this->error("$errors file(s) with syntax errors.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
