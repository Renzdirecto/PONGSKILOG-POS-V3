<?php

namespace App\Actions\Pickup;

use App\Events\PickupNotifyChanged;
use App\Jobs\SendPickupBuzz;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPickupToken;
use App\Models\User;
use App\Support\PickupBuzzPolicy;
use App\Support\PosAccess;
use App\Support\PushNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A cashier's Buzz Customer on the existing Ready notification (Phase 19.6B). Everything is decided on the server
 * under the pickup token's row lock, so double clicks, several cashier devices and replays serialize:
 *
 * - the cashier may run this Branch's POS (`PosAccess`) and the order belongs to the selected Branch;
 * - the order is a committed Take Out order the Kitchen marked Ready, its pickup link has not expired, and the
 *   customer opted in (a stored subscription);
 * - the same idempotency key returns the recorded result and sends nothing again;
 * - a 5-second cooldown after each accepted Buzz (429) and at most 5 accepted Buzzes per order (422).
 *
 * An accepted Buzz is counted and one push is queued after the commit. Delivery is best effort and never touches the
 * order or Kitchen state; a rejected endpoint is removed by the job, which makes the order non-buzzable.
 */
class BuzzPickupCustomer
{
    public function __construct(private PosAccess $access, private PickupBuzzPolicy $policy) {}

    /**
     * @return array{replayed: bool, count: int, max: int, remaining: int, last_buzzed_at: string|null, cooldown_until: string|null}
     */
    public function execute(User $user, Branch $branch, Order $order, string $idempotencyKey): array
    {
        $idempotencyKey = strtolower($idempotencyKey);

        return DB::transaction(function () use ($user, $branch, $order, $idempotencyKey): array {
            $this->access->authorize($user, $branch);
            $order = Order::query()->where('branch_id', $branch->getKey())->whereKey($order->getKey())->sharedLock()->firstOrFail();
            $pickup = OrderPickupToken::query()->where('order_id', $order->getKey())->where('branch_id', $branch->getKey())->lockForUpdate()->first();

            if ($pickup !== null && $pickup->last_buzz_key === $idempotencyKey) {
                return ['replayed' => true, ...$this->result($pickup)];
            }
            if (! $this->policy->orderAllows($order)) {
                throw ValidationException::withMessages(['buzz' => 'Only a Ready Take Out order can be buzzed.']);
            }
            if ($pickup === null || $pickup->isExpired() || ! $pickup->pushSubscription()->exists() || ! PushNotifications::enabled()) {
                throw ValidationException::withMessages(['buzz' => 'This customer has not turned on pickup notifications.']);
            }
            if ($pickup->buzz_count >= PickupBuzzPolicy::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['buzz' => 'This customer was already buzzed '.PickupBuzzPolicy::MAX_ATTEMPTS.' times. Call the order number instead.']);
            }
            if (($cooldown = $this->policy->cooldownUntil($pickup)) !== null) {
                throw new HttpException(429, 'Wait a few seconds before buzzing again.', headers: ['Retry-After' => (string) max(1, (int) ceil(now()->diffInSeconds($cooldown, true)))]);
            }

            $pickup->update([
                'buzz_count' => $pickup->buzz_count + 1,
                'last_buzzed_at' => now(),
                'last_buzz_key' => $idempotencyKey,
            ]);
            $pickupId = $pickup->id;
            DB::afterCommit(fn () => rescue(fn () => Bus::dispatch(new SendPickupBuzz($pickupId))));
            PickupNotifyChanged::dispatch((string) $branch->getKey(), (string) $order->getKey());

            return ['replayed' => false, ...$this->result($pickup)];
        }, 3);
    }

    /** @return array{count: int, max: int, remaining: int, last_buzzed_at: string|null, cooldown_until: string|null} */
    private function result(OrderPickupToken $pickup): array
    {
        return [
            'count' => $pickup->buzz_count,
            'max' => PickupBuzzPolicy::MAX_ATTEMPTS,
            'remaining' => max(0, PickupBuzzPolicy::MAX_ATTEMPTS - $pickup->buzz_count),
            'last_buzzed_at' => $pickup->last_buzzed_at?->toIso8601String(),
            'cooldown_until' => $this->policy->cooldownUntil($pickup),
        ];
    }
}
