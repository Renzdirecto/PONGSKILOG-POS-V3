<?php

namespace App\Listeners;

use App\Enums\OrderType;
use App\Events\OrderCommitted;
use App\Events\OrderUpdated;
use App\Models\Order;
use App\Support\PickupTokens;

/**
 * Gives every committed Take Out order its pickup token right after the commit (Pay Now, Pay Later, a loaded Customer
 * QR order, or a committed edit that turned a Dine In order into Take Out). Both events are dispatched only after
 * their transaction committed, so a rolled-back commitment never gets a token, and a failure here is reported and
 * swallowed: the order and its payment are already final, and the takeover issues the token again if it is missing.
 */
class IssuePickupToken
{
    public function __construct(private PickupTokens $tokens) {}

    public function handle(OrderCommitted|OrderUpdated $event): void
    {
        $orderId = $event->broadcastWith()['order_id'] ?? null;
        if (! is_string($orderId)) {
            return;
        }

        rescue(function () use ($orderId): void {
            /** One read: only a Take Out order without a token is loaded; everything else is a no-op. */
            $order = Order::query()->whereKey($orderId)->where('order_type', OrderType::TakeOut)->whereDoesntHave('pickupToken')->first();
            if ($order !== null) {
                $this->tokens->issue($order);
            }
        });
    }
}
