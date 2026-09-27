<?php

namespace App\Actions\CustomerScreens;

use App\Enums\CommercialStatus;
use App\Enums\OrderType;
use App\Events\CustomerScreenChanged;
use App\Events\CustomerScreenStatusChanged;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\Order;
use App\Support\CustomerScreenLiveState;
use App\Support\CustomerScreens;
use App\Support\CustomerScreenSettings;
use App\Support\PickupTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shows a just-committed order on the customer screen paired with the POS station that committed it (Phase 19.6).
 * It runs from the canonical commit endpoints (Pay Now and Pay Later, which create the Kitchen ticket for every order
 * type, including a loaded Customer QR order) after their transaction committed, reading the station from the
 * `X-POS-Station` header of that same request — so the confirmation needs no second request from the POS and can
 * never be skipped for Dine In. It is best effort: nothing here can change or roll back the order or its payment.
 *
 * The Take Out pickup token is ensured first (idempotent; the after-commit listener normally issued it already), so the
 * first projection the screen fetches already carries the QR. The Menu the customer was browsing closes (Ads after the
 * confirmation); an explicitly selected Customer Display stays. Each order is shown at most once per screen.
 */
class ShowOrderOnCustomerScreen
{
    /** Only an order committed moments ago is ever shown (a payment replay much later is not re-announced). */
    private const RECENT_MINUTES = 10;

    public const CART_FENCE_HEADER = 'X-Customer-Screen-Cart';

    public function __construct(
        private CustomerScreens $screens,
        private CustomerScreenLiveState $live,
        private CustomerScreenSettings $settings,
        private PickupTokens $pickups,
    ) {}

    /** After a commit endpoint succeeded: station and cart fence come from the request headers. Never throws. */
    public function afterCommit(Request $request, Branch $branch, Order $order): void
    {
        $stationId = $this->screens->stationId($request);
        if ($stationId === null) {
            return;
        }
        rescue(fn (): bool => $this->execute($branch, $stationId, $order, $this->cartFence($request)));
    }

    /** @param array{instance: string, sequence: int}|null $through the last cart send before the payment */
    public function execute(Branch $branch, string $stationId, Order $order, ?array $through): bool
    {
        if ($order->branch_id !== $branch->getKey() || $order->order_number === null || $order->committed_at === null
            || $order->committed_at->lt(now()->subMinutes(self::RECENT_MINUTES))
            || ! in_array($order->commercial_status, [CommercialStatus::Active, CommercialStatus::Completed], true)) {
            return false;
        }
        $screen = $this->screens->forStation($branch, $stationId);
        if ($screen === null || ! $screen->isPaired()) {
            return false;
        }
        if ($order->order_type === OrderType::TakeOut) {
            rescue(fn () => $this->pickups->ensureFor($order));
        }
        if ($this->live->startTakeover($screen, $order->id, $this->settings->successDurationMs($branch, $order->order_type), $through) === null) {
            return false;
        }
        $modeChanged = DB::transaction(function () use ($screen): bool {
            $locked = CustomerScreen::query()->whereKey($screen->getKey())->lockForUpdate()->first();
            if ($locked === null || ! $locked->isPaired() || $locked->mode->afterOrderSuccess() === $locked->mode) {
                return false;
            }
            $locked->update(['mode' => $locked->mode->afterOrderSuccess()]);

            return true;
        }, 3);
        CustomerScreenChanged::dispatch([$screen->channel_key], 'takeover');
        if ($modeChanged) {
            CustomerScreenStatusChanged::dispatch((string) $branch->getKey());
        }

        return true;
    }

    /** @return array{instance: string, sequence: int}|null */
    private function cartFence(Request $request): ?array
    {
        $value = $request->header(self::CART_FENCE_HEADER);
        if (! is_string($value) || preg_match('/\A([A-Za-z0-9-]{8,64}):(\d{1,10})\z/', $value, $match) !== 1 || (int) $match[2] > 2147483647) {
            return null;
        }

        return ['instance' => $match[1], 'sequence' => (int) $match[2]];
    }
}
