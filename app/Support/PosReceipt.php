<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Payment;

class PosReceipt
{
    /** @return array<string, mixed> */
    public function summary(Order $order): array
    {
        $order->load('items.modifiers', 'branchTable', 'payments.createdBy', 'branch', 'storeSession');

        return [
            'id' => $order->id, 'order_number' => $order->order_number,
            'order_type' => $order->order_type->value, 'customer_label' => $order->customer_label,
            'table_name' => $order->branchTable?->name, 'subtotal' => $order->subtotal, 'total' => $order->total,
            'payment_status' => $order->payment_status->value,
            'store_session_id' => $order->store_session_id,
            'paid_at' => $order->payments->first()?->paid_at?->toIso8601String(),
            'cashier' => $order->payments->first()?->createdBy?->name,
            'branch' => ['name' => $order->branch->name, 'code' => $order->branch->code, 'address' => $order->branch->address, 'contact' => $order->branch->contact],
            'payments' => $order->payments->map(fn (Payment $payment): array => [
                'method' => $payment->method->value, 'amount' => $payment->amount,
                'amount_received' => $payment->amount_received, 'change_amount' => $payment->change_amount,
            ])->all(),
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id, 'name' => $item->product_name_snapshot, 'unit_price' => $item->unit_price,
                'quantity' => $item->quantity, 'line_total' => $item->line_total, 'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'id' => $modifier->id, 'group_name' => $modifier->group_name_snapshot,
                    'name' => $modifier->option_name_snapshot, 'price_delta' => $modifier->price_delta_snapshot,
                    'quantity' => $modifier->quantity,
                ])->all(),
            ])->all(),
        ];
    }
}
