<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Users may only see their own payments; admins may see all.
 */
final class PaymentPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->isAdmin() && $ability === 'view' ? true : null;
    }

    public function view(User $user, Payment $payment): bool
    {
        return (int) $payment->user_id === (int) $user->id;
    }
}
