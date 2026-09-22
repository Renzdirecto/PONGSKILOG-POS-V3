<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Order;

class OrderMoney
{
    /** @return array{payments: int, adjustments: int, settled: int, outstanding: int, status: PaymentStatus} */
    public function totals(Order $order): array
    {
        $payments = $order->relationLoaded('payments')
            ? $order->payments->sum(fn ($payment): int => ExactMoney::cents($payment->amount))
            : ExactMoney::cents((string) $order->payments()->sum('amount'));
        $adjustments = $order->relationLoaded('adjustments')
            ? $order->adjustments->sum(fn ($adjustment): int => ExactMoney::cents($adjustment->amount))
            : ExactMoney::cents((string) $order->adjustments()->sum('amount'));
        $settled = max(0, $payments - $adjustments);
        $outstanding = max(0, ExactMoney::cents($order->total) - $settled);
        $status = match (true) {
            $outstanding === 0 => PaymentStatus::Paid,
            $settled > 0 => PaymentStatus::Partial,
            default => PaymentStatus::Unpaid,
        };

        return compact('payments', 'adjustments', 'settled', 'outstanding', 'status');
    }
}
