<?php

namespace App\Actions\Pickup;

use App\Events\PickupNotifyChanged;
use App\Models\OrderPickupToken;
use App\Models\PickupPushSubscription;
use Illuminate\Support\Facades\DB;

/**
 * The customer's explicit "Notify me when it's ready" on the pickup page (Phase 19.6B). Only an opted-in browser makes
 * the order buzz-capable; scanning the QR creates nothing. One endpoint per pickup token: a refresh or a second tap
 * updates the same row (idempotent), and a different browser replaces it. A subscription is bound to this token only,
 * so it never receives another order's Buzz.
 */
class SavePickupSubscription
{
    /** @param array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding: string} $subscription */
    public function execute(OrderPickupToken $pickup, array $subscription): PickupPushSubscription
    {
        return DB::transaction(function () use ($pickup, $subscription): PickupPushSubscription {
            $pickup = OrderPickupToken::query()->whereKey($pickup->getKey())->lockForUpdate()->firstOrFail();
            $existing = PickupPushSubscription::query()->where('order_pickup_token_id', $pickup->getKey())->first();
            $endpointHash = hash('sha256', $subscription['endpoint']);
            $saved = PickupPushSubscription::query()->updateOrCreate(
                ['order_pickup_token_id' => $pickup->getKey()],
                [
                    'endpoint_hash' => $endpointHash,
                    'endpoint' => $subscription['endpoint'],
                    'public_key' => $subscription['keys']['p256dh'],
                    'auth_token' => $subscription['keys']['auth'],
                    'content_encoding' => $subscription['content_encoding'],
                ],
            );
            if ($existing === null) {
                PickupNotifyChanged::dispatch($pickup->branch_id, $pickup->order_id);
            }

            return $saved;
        }, 3);
    }

    public function remove(OrderPickupToken $pickup): void
    {
        $deleted = PickupPushSubscription::query()->where('order_pickup_token_id', $pickup->getKey())->delete();
        if ($deleted > 0) {
            PickupNotifyChanged::dispatch($pickup->branch_id, $pickup->order_id);
        }
    }
}
