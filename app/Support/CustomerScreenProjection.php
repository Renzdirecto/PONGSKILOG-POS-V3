<?php

namespace App\Support;

use App\Enums\CustomerScreenMode;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\Order;

/**
 * The one authoritative state a customer screen renders (Phase 19.6A), refetched after every invalidation and after a
 * reconnect. Allowlisted fields only: the Branch name, the persistent mode, the paired station's customer-safe live
 * cart, the temporary order takeover (order number, type, same-type queue position, Take Out pickup QR) and, in
 * Customer Display mode, the existing order-number board. No ids, tender, customer, staff, stock or cost data.
 *
 * Channel names are included so the screen can subscribe; each is authorized again by the screen's own device cookie.
 */
class CustomerScreenProjection
{
    public function __construct(
        private CustomerScreens $screens,
        private CustomerScreenLiveState $live,
        private KitchenBoard $board,
        private PickupTokens $pickups,
    ) {}

    /** @return array<string, mixed> */
    public function for(?CustomerScreen $screen, string $origin): array
    {
        $branch = $screen?->isPaired() ? $screen->branch : null;
        if ($screen === null || $branch === null) {
            return [
                'status' => 'unpaired',
                'branch' => null,
                'mode' => CustomerScreenMode::Ads->value,
                'cart' => null,
                'takeover' => null,
                'board' => null,
                'channels' => $screen === null ? null : ['screen' => $this->screens->channelName($screen)],
            ];
        }
        $cart = $this->live->cart($screen);

        return [
            'status' => 'paired',
            'branch' => ['name' => $branch->name],
            'mode' => $screen->mode->value,
            'cart' => $cart === null ? null : [
                ...$cart['cart'],
                'order_type' => $cart['order_type'],
                'updated_at' => $cart['updated_at'],
            ],
            'takeover' => $this->takeover($screen, $branch, $origin),
            'board' => $screen->mode === CustomerScreenMode::CustomerDisplay ? $this->board->customerDisplay($branch) : null,
            'channels' => [
                'screen' => $this->screens->channelName($screen),
                'catalog' => 'qr-catalog.'.$branch->getKey(),
                'board' => 'branch.'.$branch->getKey().'.customer-display',
            ],
        ];
    }

    /** @return array{id: string, order_number: string, order_type: string, queue_position: int|null, remaining_ms: int, duration_ms: int, pickup: array{url: string, qr_image: string}|null}|null */
    private function takeover(CustomerScreen $screen, Branch $branch, string $origin): ?array
    {
        $current = $this->live->takeover($screen);
        if ($current === null) {
            return null;
        }
        $order = Order::query()->where('branch_id', $branch->getKey())->find($current['takeover']['order_id']);
        if ($order === null || $order->order_number === null) {
            return null;
        }
        $pickup = $order->order_type === OrderType::TakeOut ? $this->pickups->ensureFor($order) : null;

        return [
            'id' => $current['takeover']['id'],
            'order_number' => $order->order_number,
            'order_type' => $order->order_type->value,
            'queue_position' => $this->board->queuePosition($order),
            'remaining_ms' => $current['remaining_ms'],
            'duration_ms' => $current['takeover']['duration_ms'],
            'pickup' => $pickup === null || $pickup->isExpired() ? null : $this->pickups->qr($pickup, $origin),
        ];
    }
}
