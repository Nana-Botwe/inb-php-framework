<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\App;
use Core\Config;
use Core\Console\Command;
use Core\Console\EnvEditor;
use Core\Crypt;

/**
 * Rotate APP_KEY without breaking users: the old key moves to APP_PREVIOUS_KEYS, so existing
 * encrypted data, cookies, signed links and hashed tokens keep working while new ones use the new key.
 */
final class KeyRotateCommand extends Command
{
    protected string $signature = 'key:rotate {--keep=3}';

    protected string $description = 'Generate a new APP_KEY and keep the old one(s) for decryption';

    public function handle(): int
    {
        $env = App::instance()->basePath . '/.env';
        $current = (string) Config::get('app.key', '');
        if ($current === '') {
            $this->error('There is no APP_KEY to rotate. Run: php console key:generate');

            return self::FAILURE;
        }

        $keep = max(1, (int) ($this->option('keep') ?: 3));
        $previous = array_slice(array_values(array_unique([$current, ...(array) Config::get('app.previous_keys', [])])), 0, $keep);

        EnvEditor::set($env, 'APP_PREVIOUS_KEYS', implode(',', $previous));
        EnvEditor::set($env, 'APP_KEY', Crypt::generateKey());

        $this->info('New APP_KEY generated. ' . count($previous) . ' previous key(s) kept in APP_PREVIOUS_KEYS.');
        $this->line('Update the key on every server at the same time. Remove old keys once data encrypted with them has expired.');

        return self::SUCCESS;
    }
}
