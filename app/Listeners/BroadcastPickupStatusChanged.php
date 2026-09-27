<?php

namespace App\Listeners;

use App\Enums\KitchenStatus;
use App\Events\DisplayOrdersChanged;
use App\Events\PickupStatusChanged;
use App\Models\OrderPickupToken;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Branch queue changed (a commit, a Kitchen transition, a void or a Store close — exactly the events that already
 * signal the Customer Display board), so every open pickup page of that Branch whose order is still waiting or just
 * finished may show a new status or queue position. One compact `pickup.changed` invalidation goes to each of those
 * pages' own channels; the pages refetch their restricted projection. Rescued: realtime never affects the order.
 */
class BroadcastPickupStatusChanged
{
    /** A page keeps receiving updates for a while after its order was served or voided. */
    private const RECENT_MINUTES = 30;

    public function handle(DisplayOrdersChanged $event): void
    {
        $branchId = $event->broadcastWith()['branch_id'];

        rescue(function () use ($branchId): void {
            $since = now()->subMinutes(self::RECENT_MINUTES);
            $channelKeys = OrderPickupToken::query()
                ->where('branch_id', $branchId)
                ->where('expires_at', '>', now())
                ->whereHas('order', fn (Builder $order) => $order->where(fn (Builder $recent) => $recent
                    ->whereIn('kitchen_status', [KitchenStatus::Kitchen, KitchenStatus::Preparing, KitchenStatus::Ready])
                    ->orWhere('completed_at', '>=', $since)
                    ->orWhere('voided_at', '>=', $since)))
                ->limit(500)
                ->pluck('channel_key')
                ->all();

            if ($channelKeys !== []) {
                PickupStatusChanged::dispatch(array_values($channelKeys));
            }
        });
    }
}
