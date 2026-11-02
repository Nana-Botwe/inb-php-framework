<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * Background job. Create one with:  php console make:job SendInvoice
 *
 *   final class SendInvoice extends Job {
 *       public function __construct(public int $orderId) {}
 *       public function handle(): void { ... }
 *   }
 *   dispatch(new SendInvoice($order->id));            // runs now (sync) or in "php console queue:work"
 *   dispatch((new SendInvoice(5))->delay(60));         // run in 60 seconds
 *
 * Keep constructor arguments simple (IDs, strings) — the job is serialized into the database.
 */
abstract class Job
{
    /** How many times to try before the job is moved to failed_jobs. */
    public int $tries = 3;

    /** Seconds to wait before retrying a failed attempt. */
    public int $backoff = 10;

    public string $queue = 'default';

    public int $delay = 0;

    abstract public function handle(): void;

    /** Called after the last failed attempt. */
    public function failed(\Throwable $exception): void
    {
    }

    public function delay(int $seconds): static
    {
        $this->delay = max(0, $seconds);

        return $this;
    }

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }
}
