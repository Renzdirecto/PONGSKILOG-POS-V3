<?php

namespace App\Listeners;

use App\Enums\KitchenStatus;
use App\Events\KitchenStatusChanged;
use App\Events\KitchenTicketCreated;
use App\Support\PushMessage;
use App\Support\PushNotifications;

/**
 * Turns the canonical Kitchen lifecycle events into Web Push messages. Both events are dispatched after their
 * transaction commits and only for a real change (a replayed Pay Now / Pay Later or a same-status transition dispatches
 * nothing), so a rolled-back or repeated action never pushes.
 *
 * - `KitchenTicketCreated` (Pay Now and Pay Later commit) → New Kitchen Order.
 * - `KitchenStatusChanged` to Ready → Order Ready, except Done → Ready (an undo of an order already served).
 *
 * Nothing here touches the database: the job resolves recipients when it runs.
 */
class QueueKitchenPushNotifications
{
    public function handle(KitchenTicketCreated|KitchenStatusChanged $event): void
    {
        $payload = $event->broadcastWith();
        $branchId = (string) $payload['branch_id'];
        $orderId = (string) $payload['order_id'];

        if ($event instanceof KitchenTicketCreated) {
            PushNotifications::queue(PushMessage::kitchenNewOrder($branchId, $orderId));

            return;
        }

        if ($payload['to'] === KitchenStatus::Ready->value && $payload['from'] !== KitchenStatus::Done->value) {
            PushNotifications::queue(PushMessage::orderReady($branchId, $orderId));
        }
    }
}
