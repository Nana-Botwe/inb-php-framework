<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database\Relations\BelongsTo;
use Core\Model;
use Core\Payments\PaymentRequest;

/**
 * A payment attempt. The amount is stored in minor units (pesewas/kobo/cents).
 *
 * @property int    $id
 * @property string $gateway
 * @property string $reference
 * @property string $gateway_reference
 * @property int    $amount_minor
 * @property string $currency
 * @property string $status
 */
final class Payment extends Model
{
    protected static string $table = 'payments';

    protected static array $fillable = ['description'];

    protected static array $casts = [
        'amount_minor' => 'int',
        'meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function amount(): string
    {
        return PaymentRequest::toMajor((int) $this->amount_minor) . ' ' . $this->currency;
    }

    public function isPaid(): bool
    {
        return $this->status === 'success';
    }
}
