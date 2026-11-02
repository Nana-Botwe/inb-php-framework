<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Config;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Jobs are stored as plain JSON — {"job": "App\\Jobs\\SendInvoice", "data": {"invoiceId": 5, ...}} —
 * never with PHP serialize(). Restoring a job can therefore never construct arbitrary objects, and only
 * classes allowed by config/queue.php ("allowed_jobs", default: everything in App\Jobs) can run.
 *
 * Job properties must be scalars, null or arrays of those: pass IDs, not models.
 */
final class Payload
{
    /** Jobs the framework itself queues; always allowed. */
    private const FRAMEWORK_JOBS = [\Core\Mail\SendQueuedMail::class];

    public static function encode(Job $job): string
    {
        $data = [];
        foreach ((new ReflectionClass($job))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || !$property->isInitialized($job)) {
                continue;
            }
            $value = $property->getValue($job);
            self::assertPlain($value, $job::class . '::$' . $property->getName());
            $data[$property->getName()] = $value;
        }

        return (string) json_encode(['job' => $job::class, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function decode(string $payload): Job
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !is_string($decoded['job'] ?? null) || !is_array($decoded['data'] ?? null)) {
            throw new RuntimeException('Queued job payload is not valid JSON.');
        }

        $class = $decoded['job'];
        if (!self::isAllowed($class)) {
            throw new RuntimeException("Job class [$class] is not allowed (config/queue.php allowed_jobs).");
        }

        $reflection = new ReflectionClass($class);
        /** @var Job $job */
        $job = $reflection->newInstanceWithoutConstructor();

        foreach ($decoded['data'] as $name => $value) {
            if (!$reflection->hasProperty((string) $name)) {
                continue;
            }
            $property = $reflection->getProperty((string) $name);
            if ($property->isPublic() && !$property->isStatic()) {
                self::assertPlain($value, $class . '::$' . $name);
                $property->setValue($job, $value);
            }
        }

        return $job;
    }

    /** Class name from a stored payload (for listings), or null. */
    public static function jobName(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) && is_string($decoded['job'] ?? null) ? $decoded['job'] : null;
    }

    public static function isAllowed(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, Job::class)) {
            return false;
        }

        if (in_array($class, self::FRAMEWORK_JOBS, true)) {
            return true;
        }

        foreach ((array) Config::get('queue.allowed_jobs', ['App\\Jobs\\*']) as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $class || (str_ends_with($pattern, '*') && str_starts_with($class, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private static function assertPlain(mixed $value, string $where): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertPlain($item, $where);
            }

            return;
        }

        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException("$where must be a scalar or array (got " . get_debug_type($value) . '). Pass IDs, not objects.');
        }
    }
}
