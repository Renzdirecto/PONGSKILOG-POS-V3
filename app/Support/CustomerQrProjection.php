<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptAudience;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use Illuminate\Support\Arr;

class CustomerQrProjection
{
    public function __construct(private BranchCatalog $catalog, private ReceiptDocument $receipts) {}

    /** @return array<string, mixed> */
    public function catalog(Branch $branch): array
    {
        $catalog = $this->catalog->browse($branch, customization: true);
        $catalog['products'] = array_map(function (array $product): array {
            unset($product['on_hand'], $product['tracks_inventory']);
            /** Customers see whether each Size can be made, never Branch serving counts. */
            if ($product['recipe'] !== null) {
                $product['recipe']['capacity'] = null;
                $product['recipe']['sizes'] = array_map(fn (array $size): array => [...$size, 'capacity' => null], $product['recipe']['sizes']);
            }
            $product['stock_status'] = $product['availability_reason'] === 'out_of_stock' ? 'out_of_stock' : ($product['is_available'] ? 'available' : 'unavailable');
            $product['availability_reason'] = $product['is_available'] ? null : $product['stock_status'];

            return $product;
        }, $catalog['products']);

        return $catalog;
    }

    /** @return array<string, mixed> */
    public function order(Order $order): array
    {
        $order->loadMissing('items.modifiers', 'payments');
        $paidAt = $order->payment_status === PaymentStatus::Paid ? $order->payments->max('paid_at') : null;
        $expiresAt = $paidAt?->copy()->addHours(24);

        return [
            'public_tracking_id' => $order->public_tracking_id, 'order_number' => $order->order_number,
            'qr_number' => CustomerQrNumber::display($order->qr_sequence),
            'reference_number' => $order->reference_number,
            'preparing_at' => $order->preparing_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'order_type' => $order->order_type->value, 'customer_label' => $order->customer_label,
            'table_name' => $order->table_name_snapshot, 'subtotal' => $order->subtotal, 'total' => $order->total,
            'commercial_status' => $order->commercial_status->value, 'voided_at' => $order->voided_at?->toIso8601String(), 'payment_status' => $order->payment_status->value,
            'payment_term' => $order->payment_term?->value, 'kitchen_status' => $order->kitchen_status->value,
            'submitted_at' => $order->submitted_at?->toIso8601String(), 'committed_at' => $order->committed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(), 'archived_at' => $order->archived_at?->toIso8601String(),
            'paid_at' => $paidAt?->toIso8601String(), 'receipt_expires_at' => $expiresAt?->toIso8601String(),
            'receipt_available' => $order->commercial_status !== CommercialStatus::Voided && $expiresAt !== null && $expiresAt->gt(now()),
            'version' => $order->version,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                ...OperationalItemName::fromOrderItem($item), 'quantity' => $item->quantity,
                'unit_price' => $item->unit_price, 'line_total' => $item->line_total, 'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'group_name' => $modifier->group_name_snapshot, 'name' => $modifier->option_name_snapshot,
                    'semantic_role' => $modifier->semantic_role_snapshot, 'price_delta' => $modifier->price_delta_snapshot,
                    'quantity' => $modifier->quantity,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * The customer-facing receipt (the canonical `ReceiptDocument`, rendered by `DigitalReceiptCard`), shared by the
     * signed POS receipt link (SharedLink) and the Take Out pickup page (Pickup: no customer name, table or notes).
     * Only paid, non-voided orders within the receipt window.
     *
     * @return array<string, mixed>
     */
    public function publicReceipt(Order $order, ReceiptAudience $audience = ReceiptAudience::SharedLink): array
    {
        abort_unless(filled($order->order_number) && filled($order->reference_number), 404);

        return Arr::only($this->receipt($order, $audience), [
            'order_number', 'reference_number', 'paid_at', 'receipt_expires_at', 'order_type',
            'customer_label', 'table_name', 'items', 'subtotal', 'total', 'branch', 'payments',
            'layout', 'cashier', 'money', 'commercial_status', 'payment_status',
        ]);
    }

    /**
     * The Customer QR session's own receipt: its tracking projection plus the canonical receipt document.
     *
     * @return array<string, mixed>
     */
    public function receipt(Order $order, ReceiptAudience $audience = ReceiptAudience::Customer): array
    {
        $projection = $this->order($order);
        abort_if($order->commercial_status === CommercialStatus::Voided, 404);
        abort_unless($order->payment_status === PaymentStatus::Paid, 404);
        abort_unless($projection['receipt_available'], 410, 'Receipt expired. Receipts are available for 24 hours after payment.');

        return [...$projection, ...$this->receipts->for($order, $audience)];
    }
}
