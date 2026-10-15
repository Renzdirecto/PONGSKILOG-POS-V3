<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Order;

class OrderMoney
{
    /**
     * The same settlement as totals(), for reporting queries over `orders`.
     *
     * @return literal-string
     */
    public static function settledSql(): string
    {
        $payments = '(SELECT COALESCE(SUM(CAST(ROUND(pm.amount * 100) AS BIGINT)), 0) FROM payments pm WHERE pm.order_id = orders.id)';
        $adjustments = '(SELECT COALESCE(SUM(CAST(ROUND(oa.amount * 100) AS BIGINT)), 0) FROM order_adjustments oa WHERE oa.order_id = orders.id)';

        return "CASE WHEN {$payments} > {$adjustments} THEN {$payments} - {$adjustments} ELSE 0 END";
    }

    /** @return literal-string */
    public static function outstandingSql(): string
    {
        $balance = 'CAST(ROUND(orders.total * 100) AS BIGINT) - ('.self::settledSql().')';

        return "CASE WHEN {$balance} > 0 THEN {$balance} ELSE 0 END";
    }

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
