<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\App;
use Core\Config;
use Core\DB;
use Core\ErrorHandler;
use Throwable;

/**
 * Queue with "sync" (run immediately) and "database" (jobs table + php console queue:work) drivers.
 * Set QUEUE_CONNECTION in .env.
 */
final class Queue
{
    public static function push(Job $job): void
    {
        // Encode even in "sync" mode, so a job that couldn't be queued fails right away in development.
        $payload = Payload::encode($job);

        if (Config::get('queue.default', 'sync') === 'sync') {
            App::instance()->call([Payload::decode($payload), 'handle']);

            return;
        }

        DB::table('jobs')->insert([
            'queue' => $job->queue,
            'payload' => $payload,
            'attempts' => 0,
            'available_at' => time() + $job->delay,
            'reserved_at' => null,
            'created_at' => time(),
        ]);
    }

    /** Process one job. Returns false when the queue is empty. */
    public static function work(string $queue = 'default', ?callable $output = null): bool
    {
        $row = self::reserve($queue);
        if ($row === null) {
            return false;
        }

        try {
            $job = Payload::decode((string) $row['payload']);   // JSON only — never unserialize()
        } catch (Throwable $e) {
            $job = $e;   // invalid / disallowed payload: goes straight to failed_jobs below
        }
        $name = $job instanceof Job ? $job::class : (Payload::jobName((string) $row['payload']) ?? 'unknown');
        $output && $output("Processing: $name (#{$row['id']})");

        try {
            if (!$job instanceof Job) {
                throw $job instanceof Throwable ? $job : new \RuntimeException('Job payload could not be restored.');
            }
            App::instance()->call([$job, 'handle']);
            DB::table('jobs')->where('id', $row['id'])->delete();
            $output && $output("Processed:  $name");
        } catch (Throwable $e) {
            ErrorHandler::report($e);
            $attempts = (int) $row['attempts'];

            if ($job instanceof Job && $attempts < $job->tries) {
                DB::table('jobs')->where('id', $row['id'])->update([
                    'reserved_at' => null,
                    'available_at' => time() + $job->backoff,
                ]);
                $output && $output("Retrying:   $name (attempt $attempts of {$job->tries}) — {$e->getMessage()}");
            } else {
                DB::table('failed_jobs')->insert([
                    'queue' => $row['queue'],
                    'payload' => $row['payload'],
                    'exception' => (string) $e,
                    'failed_at' => date('Y-m-d H:i:s'),
                ]);
                DB::table('jobs')->where('id', $row['id'])->delete();
                if ($job instanceof Job) {
                    $job->failed($e);
                }
                $output && $output("Failed:     $name — {$e->getMessage()}");
            }
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function reserve(string $queue): ?array
    {
        $now = time();
        // Jobs reserved longer than 15 minutes are assumed to belong to a crashed worker.
        $candidate = DB::table('jobs')
            ->where('queue', $queue)
            ->where('available_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<', $now - 900))
            ->orderBy('id')
            ->first();

        if ($candidate === null) {
            return null;
        }

        // Claim it atomically: only one worker's UPDATE can match the old reserved_at value.
        $claimed = DB::table('jobs')
            ->where('id', $candidate['id'])
            ->where('attempts', $candidate['attempts'])
            ->update(['reserved_at' => $now, 'attempts' => (int) $candidate['attempts'] + 1]);

        if ($claimed !== 1) {
            return null;
        }

        $candidate['attempts'] = (int) $candidate['attempts'] + 1;

        return $candidate;
    }
}
