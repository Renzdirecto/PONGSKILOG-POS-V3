<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Payment;

class TransactionProjection
{
    public function __construct(private OrderMoney $money) {}

    /** @return array<string, mixed> */
    public function summary(Order $order): array
    {
        $totals = $this->money->totals($order);
        $firstGroup = $this->paymentGroups($order)[0] ?? null;
        $initialCash = null;
        $initialCashless = null;
        $firstPayments = $firstGroup['payments'] ?? [];
        if (is_array($firstPayments)) {
            foreach ($firstPayments as $payment) {
                if (! is_array($payment) || ! is_string($payment['method'] ?? null) || ! is_string($payment['amount'] ?? null)) {
                    continue;
                }

                if ($payment['method'] === 'cash') {
                    $initialCash = $payment['amount'];
                }

                if ($payment['method'] === 'cashless') {
                    $initialCashless = $payment['amount'];
                }
            }
        }

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'reference_number' => $order->reference_number,
            'customer_label' => $order->customer_label,
            'order_type' => $order->order_type->value,
            'table_name' => $order->table_name_snapshot ?? $order->branchTable?->name,
            'kitchen_status' => $order->kitchen_status->value,
            'payment_status' => $totals['status']->value,
            'payment_method' => $firstGroup['method'] ?? null,
            'initial_cash' => $initialCash,
            'initial_cashless' => $initialCashless,
            'cashier' => $firstGroup['cashier'] ?? null,
            'total' => $order->total,
            'original_total' => $order->original_total,
            'amount_paid' => ExactMoney::decimal($totals['payments']),
            'adjustment_total' => ExactMoney::decimal($totals['adjustments']),
            'outstanding' => ExactMoney::decimal($totals['outstanding']),
            'committed_at' => $order->committed_at?->toIso8601String(),
            'edited_at' => $order->edited_at?->toIso8601String(),
            'version' => $order->version,
            'item_count' => $order->items->sum('quantity'),
            'items_preview' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                ...OperationalItemName::fromOrderItem($item),
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'group_name' => $modifier->group_name_snapshot,
                    'semantic_role' => $modifier->semantic_role_snapshot,
                    'name' => $modifier->option_name_snapshot,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Order $order, bool $canMutate): array
    {
        $order->loadMissing('items.modifiers', 'payments.createdBy', 'payments.invoiceProof', 'adjustments.createdBy', 'branchTable');

        return [
            ...$this->summary($order),
            'can_edit' => $canMutate,
            'can_settle' => $canMutate && $this->money->totals($order)['outstanding'] > 0,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                ...OperationalItemName::fromOrderItem($item),
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'group_id' => $modifier->modifier_group_id_snapshot,
                    'option_id' => $modifier->modifier_option_id,
                    'group_name' => $modifier->group_name_snapshot,
                    'semantic_role' => $modifier->semantic_role_snapshot,
                    'name' => $modifier->option_name_snapshot,
                    'price_delta' => $modifier->price_delta_snapshot,
                ])->values()->all(),
            ])->values()->all(),
            'payment_groups' => $this->paymentGroups($order),
            'adjustments' => $order->adjustments->map(fn ($adjustment): array => [
                'id' => $adjustment->id,
                'type' => $adjustment->type,
                'amount' => $adjustment->amount,
                'reason' => $adjustment->reason,
                'created_at' => $adjustment->created_at?->toIso8601String(),
                'created_by' => $adjustment->createdBy?->name,
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function paymentGroups(Order $order): array
    {
        return array_values($order->payments->sortBy('paid_at')->groupBy(fn (Payment $payment): string => $payment->payment_group_id ?? (string) preg_replace('/:(cash|cashless)$/', '', $payment->idempotency_key))->map(function ($payments, string $groupId): array {
            $methods = $payments->pluck('method.value')->unique()->values();
            $method = $methods->count() > 1 ? 'split' : $methods->first();

            return [
                'id' => $groupId,
                'method' => $method,
                'context' => $payments->first()->payment_context ?? 'initial',
                'amount' => ExactMoney::decimal($payments->sum(fn (Payment $payment): int => ExactMoney::cents($payment->amount))),
                'paid_at' => $payments->first()->paid_at->toIso8601String(),
                'cashier' => $payments->first()->createdBy?->name,
                'payments' => $payments->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'method' => $payment->method->value,
                    'amount' => $payment->amount,
                    'amount_received' => $payment->amount_received,
                    'change_amount' => $payment->change_amount,
                    'invoice' => $payment->invoiceProof === null ? null : [
                        'name' => $payment->invoiceProof->original_name,
                        'url' => route('pos.payments.invoice.show', $payment, false),
                    ],
                ])->values()->all(),
            ];
        })->values()->all());
    }
}
