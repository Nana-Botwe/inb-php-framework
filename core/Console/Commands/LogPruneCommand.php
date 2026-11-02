<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config;
use Core\Console\Command;

/**
 * Log retention: delete old log files (scheduled daily).
 * mail.log / sms.log hold reset and verification links, so they are kept for a shorter time.
 */
final class LogPruneCommand extends Command
{
    protected string $signature = 'log:prune {--days=}';

    protected string $description = 'Delete log files older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: Config::get('logging.retention_days', 30));
        $sensitiveDays = (int) Config::get('logging.sensitive_retention_days', 3);
        $removed = 0;

        foreach (glob(storage_path('logs') . '/*.log') ?: [] as $file) {
            $name = basename($file);
            $age = (time() - (int) filemtime($file)) / 86400;

            if (in_array($name, ['mail.log', 'sms.log'], true)) {
                // Sensitive single files: truncate when older than the short retention.
                if ($age > $sensitiveDays) {
                    file_put_contents($file, '');
                    $removed++;
                }
                continue;
            }

            if ($age > $days) {
                $removed += (int) @unlink($file);
            }
        }

        $this->info("Log retention: $removed file(s) removed or cleared (keep $days days, mail/sms $sensitiveDays days).");

        return self::SUCCESS;
    }
}
