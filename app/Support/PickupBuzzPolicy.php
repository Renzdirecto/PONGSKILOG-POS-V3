<?php

namespace App\Support;

use App\Enums\KitchenStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderPickupToken;

/**
 * When a cashier may buzz a Take Out customer (Phase 19.6B). The rule is the same for the Ready list (whether the
 * button is shown) and for the Buzz request (whether it is accepted, re-checked under the token lock):
 *
 * - the order is a committed Take Out order that the Kitchen marked Ready (the existing authoritative transition);
 * - its pickup token has not expired;
 * - the customer explicitly enabled notifications on the pickup page (a stored subscription). Scanning alone is not
 *   enough, and a subscription the push service rejected is deleted, so the order stops being buzz-capable.
 *
 * Each accepted Buzz starts a 5-second server cooldown; at most 5 are accepted per order.
 */
class PickupBuzzPolicy
{
    public const MAX_ATTEMPTS = 5;

    public const COOLDOWN_SECONDS = 5;

    public function orderAllows(Order $order): bool
    {
        return $order->order_type === OrderType::TakeOut
            && $order->kitchen_status === KitchenStatus::Ready
            && app(PickupTokens::class)->eligible($order);
    }

    public function cooldownUntil(OrderPickupToken $pickup): ?string
    {
        $until = $pickup->last_buzzed_at?->addSeconds(self::COOLDOWN_SECONDS);

        return $until !== null && $until->isFuture() ? $until->toIso8601String() : null;
    }

    /**
     * The Buzz state the Ready list shows, or null when Buzz must not be offered at all (Dine In, not Ready, no token,
     * expired, or no customer subscription).
     *
     * @return array{count: int, max: int, remaining: int, last_buzzed_at: string|null, cooldown_until: string|null}|null
     */
    public function state(Order $order, ?OrderPickupToken $pickup, bool $hasSubscription): ?array
    {
        if ($pickup === null || ! $hasSubscription || $pickup->isExpired() || ! $this->orderAllows($order)) {
            return null;
        }

        return [
            'count' => $pickup->buzz_count,
            'max' => self::MAX_ATTEMPTS,
            'remaining' => max(0, self::MAX_ATTEMPTS - $pickup->buzz_count),
            'last_buzzed_at' => $pickup->last_buzzed_at?->toIso8601String(),
            'cooldown_until' => $this->cooldownUntil($pickup),
        ];
    }
}
