<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;

class PayLaterOrderSummary
{
    /** @return array<string, mixed> */
    public function summary(Order $order): array
    {
        $order->loadMissing('items.modifiers', 'branchTable', 'createdBy');

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'qr_number' => CustomerQrNumber::display($order->qr_sequence),
            'reference_number' => $order->reference_number,
            'order_type' => $order->order_type->value,
            'customer_label' => $order->customer_label,
            'table_name' => $order->branchTable?->name,
            'subtotal' => $order->subtotal,
            'total' => $order->total,
            'commercial_status' => $order->commercial_status->value,
            'payment_status' => $order->payment_status->value,
            'payment_term' => $order->payment_term?->value,
            'kitchen_status' => $order->kitchen_status->value,
            'store_session_id' => $order->store_session_id,
            'committed_at' => $order->committed_at?->toIso8601String(),
            'cashier' => $order->createdBy?->name,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                ...OperationalItemName::fromOrderItem($item),
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'id' => $modifier->id,
                    'group_name' => $modifier->group_name_snapshot,
                    'semantic_role' => $modifier->semantic_role_snapshot,
                    'name' => $modifier->option_name_snapshot,
                    'price_delta' => $modifier->price_delta_snapshot,
                    'quantity' => $modifier->quantity,
                ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function qr(Order $order): array
    {
        return [...$this->summary($order), 'source' => $order->source->value,
            'branch_table_id' => $order->branch_table_id, 'submitted_at' => $order->submitted_at?->toIso8601String(),
            'archived_at' => $order->archived_at?->toIso8601String(), 'archive_reason' => $order->archive_reason,
            'table_name' => $order->table_name_snapshot, 'version' => $order->version];
    }
}
