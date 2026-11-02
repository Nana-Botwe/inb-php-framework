<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config;
use Core\Console\Command;
use Core\Database;
use RuntimeException;

/**
 * Database backup to storage/backups (never inside public/), gzip-compressed, with retention.
 *
 *   php console db:backup                 # default connection
 *   php console db:backup --keep=30       # keep the newest 30 backups of this connection
 *
 * MySQL/MariaDB use mysqldump, PostgreSQL pg_dump (passwords passed by environment variable, never on
 * the command line), SQLite an online "VACUUM INTO" copy. Other databases: use their native tools.
 */
final class DbBackupCommand extends Command
{
    protected string $signature = 'db:backup {connection?} {--keep=}';

    protected string $description = 'Back up the database to storage/backups (compressed, with retention)';

    public function handle(): int
    {
        $name = $this->argument('connection');
        $db = $name === null ? Database::instance() : Database::connection($name);
        $config = $db->getConfig();
        $dir = storage_path('backups');
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir");
        }

        $file = sprintf('%s/%s-%s.%s', $dir, $db->getName(), date('Ymd-His'), $db->driver() === 'sqlite' ? 'sqlite.gz' : 'sql.gz');

        match ($db->driver()) {
            'mysql' => $this->dump([(string) Config::get('backup.mysqldump', 'mysqldump'), '--single-transaction', '--routines', '--triggers', '--no-tablespaces',
                '--host=' . ($config['host'] ?? '127.0.0.1'), '--port=' . ($config['port'] ?? 3306), '--user=' . ($config['username'] ?? ''), (string) ($config['database'] ?? '')],
                ['MYSQL_PWD' => $db->passwordForExternalTool()], $file),
            'pgsql' => $this->dump([(string) Config::get('backup.pg_dump', 'pg_dump'), '--no-owner', '--clean', '--if-exists',
                '--host=' . ($config['host'] ?? '127.0.0.1'), '--port=' . ($config['port'] ?? 5432), '--username=' . ($config['username'] ?? ''), (string) ($config['database'] ?? '')],
                ['PGPASSWORD' => $db->passwordForExternalTool()], $file),
            'sqlite' => $this->sqlite($db, $file),
            default => throw new RuntimeException('Automatic backups support MySQL, MariaDB, PostgreSQL and SQLite. For ' . $db->driver() . ' use the database\'s own backup tool.'),
        };

        @chmod($file, 0600);
        $this->info('Backup written: ' . $file . ' (' . round(filesize($file) / 1024, 1) . ' KB)');
        $this->prune($dir, $db->getName(), (int) ($this->option('keep') ?: Config::get('backup.keep', 14)));

        return self::SUCCESS;
    }

    /** @param string[] $command @param array<string, string> $env */
    private function dump(array $command, array $env, string $file): void
    {
        $out = gzopen($file, 'wb6');
        if ($out === false) {
            throw new RuntimeException("Cannot write $file");
        }

        // Array command = no shell, so no injection through database names or passwords.
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        if (!is_resource($process)) {
            gzclose($out);
            throw new RuntimeException('Could not start ' . $command[0] . '. Set its full path in config/backup.php.');
        }

        while (!feof($pipes[1])) {
            gzwrite($out, (string) fread($pipes[1], 1 << 16));
        }
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        gzclose($out);

        if (proc_close($process) !== 0) {
            @unlink($file);
            throw new RuntimeException($command[0] . ' failed: ' . trim((string) $errors));
        }
    }

    private function sqlite(Database $db, string $file): void
    {
        $tmp = $file . '.tmp';
        $db->pdo()->exec('VACUUM INTO ' . $db->pdo()->quote($tmp));
        $in = fopen($tmp, 'rb');
        $out = gzopen($file, 'wb6');
        while ($in && !feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 16));
        }
        fclose($in);
        gzclose($out);
        @unlink($tmp);
    }

    private function prune(string $dir, string $connection, int $keep): void
    {
        $files = glob($dir . '/' . $connection . '-*.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, $keep)) as $old) {
            @unlink($old);
            $this->line('Removed old backup ' . basename($old));
        }
    }
}
