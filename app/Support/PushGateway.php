<?php

namespace App\Support;

use App\Models\PushSubscription;

/**
 * Sends one encrypted Web Push message to one browser subscription through its push service.
 */
interface PushGateway
{
    /**
     * @return int|null the push service's HTTP status, or null when the request never got a response
     */
    public function deliver(PushSubscription $subscription, PushMessage $message, string $payload): ?int;
}
