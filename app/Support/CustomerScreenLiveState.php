<?php

namespace App\Support;

use App\Models\CustomerScreen;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ephemeral, non-authoritative customer screen state (Phase 19.6A): the paired station's live cart and the temporary
 * order takeover. It lives only in the cache store (Redis in production) with short lifetimes, never in the database:
 * an unfinished POS cart is not an order, moves no stock and has no payment state. Losing it only blanks the Live Cart
 * until the POS sends its next change.
 *
 * Latest-wins: each POS page instance numbers its cart sends; a send with an older number from the same instance is
 * ignored, so a delayed request can never overwrite a newer cart. A takeover also clears the cart and remembers the
 * instance/sequence it cleared through, so a cart send that was already in flight cannot bring the paid cart back.
 *
 * The takeover's countdown starts when the screen reports it is actually showing (order data and, for Take Out, the
 * pickup QR rendered) — never when the order was committed — so a slow network or QR rendering cannot eat the
 * customer's scan window. A takeover the screen never shows is dropped after `TAKEOVER_PENDING_MS`, and each order is
 * announced at most once per screen, so a replayed payment request never shows it twice.
 *
 * @phpstan-import-type CartProjection from CustomerScreenCart
 *
 * @phpstan-type StoredCart array{instance: string, sequence: int, user_id: int, order_type: string|null, cart: CartProjection, updated_at: string}
 * @phpstan-type StoredTakeover array{id: string, order_id: string, duration_ms: int, created_at_ms: int, shown_at_ms: int|null}
 */
class CustomerScreenLiveState
{
    /** A cart the POS stopped updating (closed tab, crashed device) disappears from the screen after this. */
    public const CART_TTL_SECONDS = 900;

    /** A takeover the screen has not started showing within this time (screen offline or asleep) is dropped. */
    public const TAKEOVER_PENDING_MS = 20_000;

    /** An order announced on a screen is never announced there again for this long (payment replays, retries). */
    private const ANNOUNCED_SECONDS = 900;

    /**
     * Stores a cart send unless an equal or newer send of the same page instance is already stored.
     *
     * @param  CartProjection  $cart
     * @return bool whether the stored cart changed
     */
    public function putCart(CustomerScreen $screen, string $instance, int $sequence, int $userId, ?string $orderType, array $cart): bool
    {
        return $this->locked($screen, function () use ($screen, $instance, $sequence, $userId, $orderType, $cart): bool {
            $current = $this->cart($screen);
            $floor = Cache::get($this->key($screen, 'cleared'));
            if ($this->isStale($current, $instance, $sequence) || $this->isStale(is_array($floor) ? $floor : null, $instance, $sequence)) {
                return false;
            }
            if ($cart['lines'] === []) {
                Cache::forget($this->key($screen, 'cart'));

                return $current !== null;
            }
            Cache::put($this->key($screen, 'cart'), [
                'instance' => $instance,
                'sequence' => $sequence,
                'user_id' => $userId,
                'order_type' => $orderType,
                'cart' => $cart,
                'updated_at' => now()->toIso8601String(),
            ], self::CART_TTL_SECONDS);
            $this->rememberCartUser($userId, $screen);

            return true;
        }) ?? false;
    }

    /** @return StoredCart|null */
    public function cart(CustomerScreen $screen): ?array
    {
        return $this->storedCart(Cache::get($this->key($screen, 'cart')));
    }

    /**
     * Queues the temporary takeover for a committed order and clears the cart it replaced. `$through` is the last cart
     * send the POS made before the payment, so that send (or an older one still in flight) cannot restore the cart.
     * Returns null when this order was already announced on this screen (or the screen state is busy).
     *
     * @param  array{instance: string, sequence: int}|null  $through
     * @return StoredTakeover|null
     */
    public function startTakeover(CustomerScreen $screen, string $orderId, int $durationMs, ?array $through): ?array
    {
        return $this->locked($screen, function () use ($screen, $orderId, $durationMs, $through): ?array {
            $announced = $this->key($screen, 'announced:'.$orderId);
            if (Cache::has($announced)) {
                return null;
            }
            $takeover = [
                'id' => Str::random(16),
                'order_id' => $orderId,
                'duration_ms' => $durationMs,
                'created_at_ms' => (int) now()->getTimestampMs(),
                'shown_at_ms' => null,
            ];
            Cache::put($this->key($screen, 'takeover'), $takeover, 120);
            Cache::put($announced, true, self::ANNOUNCED_SECONDS);
            Cache::forget($this->key($screen, 'cart'));
            if ($through !== null) {
                Cache::put($this->key($screen, 'cleared'), $through, self::CART_TTL_SECONDS);
            }

            return $takeover;
        });
    }

    /**
     * The current takeover: still waiting to be shown (`remaining_ms` null) or showing with the milliseconds left.
     * Null once it ended or was never shown in time.
     *
     * @return array{takeover: StoredTakeover, remaining_ms: int|null}|null
     */
    public function takeover(CustomerScreen $screen): ?array
    {
        $takeover = $this->storedTakeover(Cache::get($this->key($screen, 'takeover')));
        if ($takeover === null) {
            return null;
        }
        $now = (int) now()->getTimestampMs();
        if ($takeover['shown_at_ms'] === null) {
            return $now - $takeover['created_at_ms'] < self::TAKEOVER_PENDING_MS ? ['takeover' => $takeover, 'remaining_ms' => null] : null;
        }
        $remaining = $takeover['shown_at_ms'] + $takeover['duration_ms'] - $now;

        return $remaining > 0 ? ['takeover' => $takeover, 'remaining_ms' => $remaining] : null;
    }

    /**
     * The screen reports the takeover is on screen: its countdown starts now (the first report wins; later reports and
     * reloads get the time left). Returns the milliseconds left, or null for an unknown or ended takeover.
     */
    public function markShown(CustomerScreen $screen, string $takeoverId): ?int
    {
        return $this->locked($screen, function () use ($screen, $takeoverId): ?int {
            $current = $this->takeover($screen);
            if ($current === null || ! hash_equals($current['takeover']['id'], $takeoverId)) {
                return null;
            }
            if ($current['remaining_ms'] !== null) {
                return $current['remaining_ms'];
            }
            Cache::put($this->key($screen, 'takeover'), [...$current['takeover'], 'shown_at_ms' => (int) now()->getTimestampMs()], 120);

            return $current['takeover']['duration_ms'];
        });
    }

    /** Pairing changed or ended: nothing of the previous station may remain on the screen. */
    public function reset(CustomerScreen $screen): void
    {
        foreach (['cart', 'takeover', 'cleared'] as $part) {
            Cache::forget($this->key($screen, $part));
        }
    }

    /**
     * The screens that currently show a cart sent by this account (for sign-out cleanup).
     *
     * @return list<string>
     */
    public function screensWithCartsOf(int $userId): array
    {
        $ids = Cache::get('customer-screen:user:'.$userId.':screens');

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /**
     * Clears the carts this account sent (sign-out) and returns the screens whose cart was removed.
     *
     * @return list<string>
     */
    public function forgetCartsOf(int $userId): array
    {
        $cleared = [];
        foreach ($this->screensWithCartsOf($userId) as $screenId) {
            $key = 'customer-screen:'.$screenId.':cart';
            $cart = Cache::get($key);
            if (is_array($cart) && ($cart['user_id'] ?? null) === $userId) {
                Cache::forget($key);
                $cleared[] = $screenId;
            }
        }
        Cache::forget('customer-screen:user:'.$userId.':screens');

        return $cleared;
    }

    /**
     * The cache can hold anything (another deploy's shape, a flush in progress): only a complete, well-typed cart is ever
     * shown; anything else counts as no cart.
     *
     * @return StoredCart|null
     */
    private function storedCart(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['instance'] ?? null) || ! is_int($value['sequence'] ?? null)
            || ! is_int($value['user_id'] ?? null) || ! is_string($value['updated_at'] ?? null) || ! is_array($value['cart'] ?? null)
            || ! is_string($value['cart']['total'] ?? null) || ! is_int($value['cart']['item_count'] ?? null) || ! is_array($value['cart']['lines'] ?? null)) {
            return null;
        }
        $lines = [];
        foreach ($value['cart']['lines'] as $line) {
            if (! is_array($line) || ! is_string($line['key'] ?? null) || ! is_string($line['name'] ?? null)
                || ! is_int($line['quantity'] ?? null) || ! is_string($line['amount'] ?? null)) {
                return null;
            }
            $lines[] = [
                'key' => $line['key'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'details' => $this->strings($line['details'] ?? null),
                'instructions' => $this->strings($line['instructions'] ?? null),
                'amount' => $line['amount'],
            ];
        }
        $orderType = $value['order_type'] ?? null;

        return [
            'instance' => $value['instance'],
            'sequence' => $value['sequence'],
            'user_id' => $value['user_id'],
            'order_type' => is_string($orderType) ? $orderType : null,
            'cart' => ['lines' => $lines, 'total' => $value['cart']['total'], 'item_count' => $value['cart']['item_count']],
            'updated_at' => $value['updated_at'],
        ];
    }

    /** @return StoredTakeover|null */
    private function storedTakeover(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['id'] ?? null) || ! is_string($value['order_id'] ?? null)
            || ! is_int($value['duration_ms'] ?? null) || ! is_int($value['created_at_ms'] ?? null)) {
            return null;
        }
        $shownAt = $value['shown_at_ms'] ?? null;

        return [
            'id' => $value['id'],
            'order_id' => $value['order_id'],
            'duration_ms' => $value['duration_ms'],
            'created_at_ms' => $value['created_at_ms'],
            'shown_at_ms' => is_int($shownAt) ? $shownAt : null,
        ];
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    /** @param array<mixed>|null $stored */
    private function isStale(?array $stored, string $instance, int $sequence): bool
    {
        return $stored !== null && ($stored['instance'] ?? null) === $instance && $sequence <= (int) ($stored['sequence'] ?? 0);
    }

    private function rememberCartUser(int $userId, CustomerScreen $screen): void
    {
        $key = 'customer-screen:user:'.$userId.':screens';
        $ids = $this->screensWithCartsOf($userId);
        if (! in_array($screen->id, $ids, true)) {
            $ids[] = $screen->id;
        }
        Cache::put($key, array_slice($ids, -20), self::CART_TTL_SECONDS);
    }

    private function key(CustomerScreen $screen, string $part): string
    {
        return 'customer-screen:'.$screen->id.':'.$part;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function locked(CustomerScreen $screen, callable $callback): mixed
    {
        try {
            return Cache::lock($this->key($screen, 'lock'), 5)->block(3, $callback);
        } catch (LockTimeoutException) {
            return null;
        }
    }
}
