<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\OrderItem;
use App\Models\OrderPickupToken;

/**
 * The public pickup page's projection (Phase 19.6), read with nothing but the pickup token. Exactly: the order number,
 * Take Out, Preparing / Ready / Done (or no longer active), its overall and Take Out queue positions from the one queue
 * authority (`KitchenBoard::queue()`, null once it is no longer waiting), a customer-safe order summary from the
 * order's own historical snapshots, whether its receipt can be viewed, the Branch's configured customer links and
 * whether Ready notifications are available / turned on. No customer name, table, notes, cost, payment details, staff,
 * Branch-internal or id data, and no action that could change the order.
 *
 * @phpstan-type PickupProjection array{order_number: string, order_type: 'take_out', status: 'preparing'|'ready'|'done'|'unavailable', queue_position: int|null, overall_position: int|null, summary: array{items: list<array{name: string, quantity: int, details: list<string>, instructions: list<string>, amount: string}>, subtotal: string, total: string}, receipt_available: bool, links: array{facebook: string|null, website: string|null, maps: string|null}, notifications: array{available: bool, public_key: string|null, subscribed: bool}, channel: string}
 */
class PickupStatus
{
    public function __construct(private KitchenBoard $board, private CustomerScreenSettings $settings) {}

    /**
     * The projection, or null when the order is not (or no longer) a Take Out order, so the page answers 404.
     *
     * @return PickupProjection|null
     */
    public function for(OrderPickupToken $pickup): ?array
    {
        $order = $pickup->order;
        if ($order->order_type !== OrderType::TakeOut || $order->order_number === null) {
            return null;
        }
        $active = in_array($order->commercial_status, [CommercialStatus::Active, CommercialStatus::Completed], true);
        $status = match (true) {
            ! $active => 'unavailable',
            $order->kitchen_status === KitchenStatus::Ready => 'ready',
            $order->kitchen_status === KitchenStatus::Done => 'done',
            default => 'preparing',
        };
        $order->loadMissing(['branch', 'items' => fn ($query) => $query->orderBy('created_at')->orderBy('id'), 'items.modifiers']);
        $queue = $status === 'preparing' ? $this->board->queue($order, 0) : null;

        return [
            'order_number' => $order->order_number,
            'order_type' => 'take_out',
            'status' => $status,
            'queue_position' => $queue['type_position'] ?? null,
            'overall_position' => $queue['overall_position'] ?? null,
            'summary' => [
                'items' => array_values($order->items->map(fn (OrderItem $item): array => CustomerScreenCart::customerLine($item))->all()),
                'subtotal' => (string) $order->subtotal,
                'total' => (string) $order->total,
            ],
            'receipt_available' => $order->payment_status === PaymentStatus::Paid && $order->commercial_status !== CommercialStatus::Voided,
            'links' => $this->settings->customerLinks($order->branch),
            'notifications' => [
                'available' => PushNotifications::enabled() && in_array($status, ['preparing', 'ready'], true),
                'public_key' => PushNotifications::publicKey(),
                'subscribed' => $pickup->pushSubscription()->exists(),
            ],
            'channel' => 'pickup.'.$pickup->channel_key,
        ];
    }
}
