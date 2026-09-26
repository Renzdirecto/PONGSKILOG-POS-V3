<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderType;
use App\Models\OrderPickupToken;

/**
 * The public pickup page's projection (Phase 19.6B), read with nothing but the pickup token. Exactly: the order
 * number, Take Out, Preparing / Ready / Done (or no longer active), the server-derived position in the Take Out queue
 * and whether Ready notifications are available / turned on for this order. No customer, item, money, payment,
 * staff, Branch-internal or id data, and no action that could change the order.
 */
class PickupStatus
{
    public function __construct(private KitchenBoard $board) {}

    /**
     * The projection, or null when the order is not (or no longer) a Take Out order, so the page answers 404.
     *
     * @return array{order_number: string, order_type: 'take_out', status: 'preparing'|'ready'|'done'|'unavailable', queue_position: int|null, notifications: array{available: bool, public_key: string|null, subscribed: bool}, channel: string}|null
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

        return [
            'order_number' => $order->order_number,
            'order_type' => 'take_out',
            'status' => $status,
            'queue_position' => $status === 'preparing' ? $this->board->queuePosition($order) : null,
            'notifications' => [
                'available' => PushNotifications::enabled() && in_array($status, ['preparing', 'ready'], true),
                'public_key' => PushNotifications::publicKey(),
                'subscribed' => $pickup->pushSubscription()->exists(),
            ],
            'channel' => 'pickup.'.$pickup->channel_key,
        ];
    }
}
