<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use Core\Console\Command;
use Core\DB;

/**
 * Re-run fulfilment for a paid payment whose "payment.succeeded" listeners kept failing
 * (after you fixed the cause). Also lists every paid payment that is still waiting.
 *
 *   php console payments:fulfil          # list paid but unfulfilled payments
 *   php console payments:fulfil 42       # try payment #42 again now
 */
final class FulfilPayment extends Command
{
    protected string $signature = 'payments:fulfil {id?}';

    protected string $description = 'List paid payments that are not fulfilled yet, or retry one';

    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function handle(): int
    {
        $id = $this->argument('id');

        if ($id === null || $id === '') {
            $rows = DB::table('payments')->where('status', 'success')->whereNull('fulfilled_at')->orderBy('id')->limit(200)->get();
            if ($rows->isEmpty()) {
                $this->info('Every paid payment has been fulfilled.');

                return self::SUCCESS;
            }
            foreach ($rows as $row) {
                $this->line(sprintf('#%d  %s  paid %s  attempts %d  %s', $row['id'], $row['reference'], (string) $row['paid_at'], (int) $row['fulfillment_attempts'], (string) ($row['fulfillment_error'] ?? '')));
            }
            $this->warn(count($rows) . ' paid payment(s) waiting for fulfilment.');

            return self::SUCCESS;
        }

        $payment = Payment::find((int) $id);
        if ($payment === null || $payment->status !== 'success') {
            $this->error("Payment #$id does not exist or is not paid.");

            return self::FAILURE;
        }
        if ($payment->fulfilled_at !== null) {
            $this->info("Payment #$id is already fulfilled.");

            return self::SUCCESS;
        }

        // A human decided to try again: reset the attempt counter and the back-off.
        DB::table('payments')->where('id', $payment->id)->whereNull('fulfilled_at')
            ->update(['fulfillment_attempts' => 0, 'fulfillment_locked_until' => null]);

        if ($this->payments->fulfil($payment)) {
            $this->info("Payment #$id fulfilled.");

            return self::SUCCESS;
        }

        $this->error("Fulfilment of payment #$id failed again: " . (string) $payment->refresh()->fulfillment_error);

        return self::FAILURE;
    }
}
