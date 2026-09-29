<?php

namespace App\Enums;

/**
 * The only Web Push messages PONGSKILOG sends. The service worker shows fixed, generic texts per type (never order,
 * customer, money or Staff details) and only opens same-app paths from its own allowlist.
 */
enum PushMessageType: string
{
    case KitchenNewOrder = 'kitchen.new_order';
    case OrderReady = 'order.ready';
    case AdminAlert = 'admin.alert';

    /** The same-app page a tap opens; the server still authorizes it on arrival. */
    public function url(): string
    {
        return match ($this) {
            self::KitchenNewOrder => route('workspaces.kitchen', absolute: false),
            self::OrderReady => route('workspaces.cashier', absolute: false),
            self::AdminAlert => route('super-admin.notifications', absolute: false),
        };
    }

    /** Seconds a push service keeps an undelivered message: an order signal is stale after a few minutes. */
    public function timeToLive(): int
    {
        return match ($this) {
            self::KitchenNewOrder, self::OrderReady => 600,
            self::AdminAlert => 86400,
        };
    }

    /** RFC 8030 urgency: order signals may wake a device; alerts can wait for the next convenient delivery. */
    public function urgency(): string
    {
        return match ($this) {
            self::KitchenNewOrder, self::OrderReady => 'high',
            self::AdminAlert => 'normal',
        };
    }
}
