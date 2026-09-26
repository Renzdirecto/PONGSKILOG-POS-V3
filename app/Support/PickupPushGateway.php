<?php

namespace App\Support;

use App\Models\PickupPushSubscription;

/**
 * Sends one encrypted Web Push message to one customer pickup subscription. Separate from `PushGateway` on purpose:
 * staff messages and their recipient resolution can never reach a pickup subscription, and the Buzz can never reach a
 * staff device.
 */
interface PickupPushGateway
{
    /**
     * @return int|null the push service's HTTP status, or null when the request never got a response
     */
    public function deliverPickup(PickupPushSubscription $subscription, string $payload, string $topic): ?int;
}
