<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PaymentService;
use Core\Console\Command;

/**
 * Retries stored webhooks, re-verifies pending payments, finishes interrupted fulfilment and closes
 * expired payments. Scheduled every 5 minutes in routes/console.php.
 */
final class ReconcilePayments extends Command
{
    protected string $signature = 'payments:reconcile';

    protected string $description = 'Retry webhooks, re-check pending payments, finish interrupted fulfilment, close expired ones';

    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function handle(): int
    {
        $stats = $this->payments->reconcile();
        $this->info(sprintf(
            'Webhook events retried: %d · pending payments checked: %d · newly paid: %d · fulfilment completed: %d · expired: %d',
            $stats['events'],
            $stats['checked'],
            $stats['paid'],
            $stats['fulfilled'],
            $stats['expired'],
        ));

        return self::SUCCESS;
    }
}
