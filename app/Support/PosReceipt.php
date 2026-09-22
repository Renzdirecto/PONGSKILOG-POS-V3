<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Payment;

class PosReceipt
{
    public function __construct(private OrderMoney $money) {}

    /** @return array<string, mixed> */
    public function summary(Order $order): array
    {
        $order->load('items.modifiers', 'branchTable', 'payments.createdBy', 'payments.invoiceProof', 'adjustments', 'branch', 'storeSession');
        $money = $this->money->totals($order);
        $payments = $order->payments->sortBy('paid_at');

        return [
            'id' => $order->id, 'order_number' => $order->order_number, 'reference_number' => $order->reference_number,
            'order_type' => $order->order_type->value, 'customer_label' => $order->customer_label,
            'table_name' => $order->branchTable?->name, 'subtotal' => $order->subtotal, 'total' => $order->total,
            'payment_status' => $order->payment_status->value,
            'amount_paid' => ExactMoney::decimal($money['payments']),
            'adjustment_total' => ExactMoney::decimal($money['adjustments']),
            'outstanding' => ExactMoney::decimal($money['outstanding']),
            'store_session_id' => $order->store_session_id,
            'paid_at' => $payments->first()?->paid_at?->toIso8601String(),
            'cashier' => $payments->first()?->createdBy?->name,
            'branch' => ['name' => $order->branch->receipt_name ?? $order->branch->name, 'code' => $order->branch->code, 'address' => $order->branch->receipt_address ?? $order->branch->address, 'contact' => $order->branch->receipt_contact ?? $order->branch->contact, 'footer' => $order->branch->receipt_footer, 'show_logo' => $order->branch->receipt_show_logo, 'logo_url' => $order->branch->receipt_logo_path ? route('branches.receipt-logo', $order->branch, false).'?v='.md5($order->branch->receipt_logo_path) : '/images/branding/logo.png'],
            'payments' => $payments->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'method' => $payment->method->value, 'amount' => $payment->amount,
                'amount_received' => $payment->amount_received, 'change_amount' => $payment->change_amount,
                'payment_group_id' => $payment->payment_group_id ?? preg_replace('/:(cash|cashless)$/', '', $payment->idempotency_key),
                'payment_context' => $payment->payment_context,
                'invoice' => $payment->invoiceProof === null ? null : [
                    'name' => $payment->invoiceProof->original_name,
                    'url' => route('pos.payments.invoice.show', $payment, false),
                ],
            ])->all(),
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id, ...OperationalItemName::fromOrderItem($item), 'unit_price' => $item->unit_price,
                'quantity' => $item->quantity, 'line_total' => $item->line_total, 'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'id' => $modifier->id, 'group_name' => $modifier->group_name_snapshot,
                    'semantic_role' => $modifier->semantic_role_snapshot,
                    'name' => $modifier->option_name_snapshot, 'price_delta' => $modifier->price_delta_snapshot,
                    'quantity' => $modifier->quantity,
                ])->all(),
            ])->all(),
        ];
    }
}
