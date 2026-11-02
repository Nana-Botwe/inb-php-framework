<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Every genuine (signature-checked) webhook is stored before processing, so nothing is lost when
 * the provider calls before our payment row exists or when verification fails temporarily.
 * "php console payments:reconcile" retries unmatched/failed events.
 *
 * status: received | processed | unmatched | failed
 */
final class PaymentWebhookEvent extends Model
{
    protected static string $table = 'payment_webhook_events';

    protected static array $fillable = [];

    protected static array $casts = ['attempts' => 'int', 'processed_at' => 'datetime'];
}
