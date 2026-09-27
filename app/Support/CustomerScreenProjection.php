<?php

namespace App\Support;

use App\Enums\CustomerScreenMode;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\Order;

/**
 * The one authoritative state a customer screen renders (Phase 19.6), refetched after every invalidation and after a
 * reconnect. Allowlisted fields only: the Branch name, the persistent mode, the paired station's customer-safe live
 * cart, the temporary order confirmation (order number, type, overall and same-type queue positions, a window of the
 * active queue by order number, the Take Out pickup QR) and, in Customer Display mode, the existing order-number
 * board. No ids, tender, customer names, staff, stock or cost data.
 *
 * Channel names are included so the screen can subscribe; each is authorized again by the screen's own device cookie.
 *
 * @phpstan-type TakeoverProjection array{id: string, order_number: string, order_type: string, duration_ms: int, remaining_ms: int|null, overall_position: int|null, type_position: int|null, queue: list<array{position: int, order_number: string, order_type: string, current: bool}>, queue_total: int, pickup: array{url: string, qr_image: string}|null}
 */
class CustomerScreenProjection
{
    /** Optional customer-screen success cue: played only when a Branch-approved file is placed at this public path. */
    public const SUCCESS_SOUND_PATH = 'audio/customer-screen-success.mp3';

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
                'sound_url' => null,
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
            'sound_url' => is_file(public_path(self::SUCCESS_SOUND_PATH)) ? '/'.self::SUCCESS_SOUND_PATH : null,
            'channels' => [
                'screen' => $this->screens->channelName($screen),
                'catalog' => 'qr-catalog.'.$branch->getKey(),
                'board' => 'branch.'.$branch->getKey().'.customer-display',
            ],
        ];
    }

    /**
     * The order confirmation while it waits to be shown (`remaining_ms` null: the screen starts the countdown once the
     * number and, for Take Out, the QR are on screen) or while it shows. A Take Out order without a usable pickup token
     * (issuance failed) is still confirmed, just without a QR, so the screen never waits for it forever.
     *
     * @return TakeoverProjection|null
     */
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
        $order->setRelation('branch', $branch);
        $queue = $this->board->queue($order);
        $pickup = $order->order_type === OrderType::TakeOut ? rescue(fn () => $this->pickups->ensureFor($order)) : null;

        return [
            'id' => $current['takeover']['id'],
            'order_number' => $order->order_number,
            'order_type' => $order->order_type->value,
            'duration_ms' => $current['takeover']['duration_ms'],
            'remaining_ms' => $current['remaining_ms'],
            'overall_position' => $queue['overall_position'],
            'type_position' => $queue['type_position'],
            'queue' => $queue['rows'],
            'queue_total' => $queue['total'],
            'pickup' => $pickup === null || $pickup->isExpired() ? null : $this->pickups->qr($pickup, $origin),
        ];
    }
}
