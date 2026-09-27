<?php

namespace App\Jobs;

use App\Events\PickupNotifyChanged;
use App\Models\OrderPickupToken;
use App\Support\PickupBuzzPolicy;
use App\Support\PickupPushGateway;
use App\Support\PickupTokens;
use App\Support\PushNotifications;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one accepted Buzz to the customer's own pickup subscription (Phase 19.6B), re-validated when it runs: the
 * order must still be a Ready Take Out order with an unexpired link and a stored subscription, or nothing is sent.
 *
 * - 404/410 (unsubscribed or expired browser) and 400/401/403 (malformed or made for another key) delete the
 *   subscription: the order stops being buzz-capable and the POS Ready list refetches.
 * - A transient failure (no response, 429, 5xx) is retried once after a few seconds, never repeatedly.
 * - Nothing here reads or writes order, Kitchen or payment state.
 *
 * The encrypted payload carries the order number and this order's own pickup path (the customer's browser already
 * holds that link); logs carry only the pickup id, status or error class — never the endpoint, keys or token.
 */
class SendPickupBuzz implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 2;

    public const RETRY_DELAY_SECONDS = 5;

    public int $tries = 1;

    public function __construct(public string $pickupTokenId, public int $attempt = 1) {}

    public function handle(PickupPushGateway $gateway, PickupTokens $tokens, PickupBuzzPolicy $policy): void
    {
        if (! PushNotifications::enabled()) {
            return;
        }
        $pickup = OrderPickupToken::query()->with(['order', 'pushSubscription'])->find($this->pickupTokenId);
        $subscription = $pickup?->pushSubscription;
        if ($pickup === null || $subscription === null || $pickup->isExpired() || ! $policy->orderAllows($pickup->order)) {
            return;
        }
        $token = $tokens->rawToken($pickup);
        if ($token === null) {
            return;
        }
        $payload = json_encode([
            'v' => 1,
            'type' => 'pickup.ready',
            'tag' => 'pickup-ready:'.$pickup->id,
            'order_number' => (string) $pickup->order->order_number,
            'url' => $tokens->path($token),
        ], JSON_THROW_ON_ERROR);
        $topic = substr(rtrim(strtr(base64_encode(hash('sha256', 'pickup-ready:'.$pickup->id, true)), '+/', '-_'), '='), 0, 32);

        try {
            $status = $gateway->deliverPickup($subscription, $payload, $topic);
        } catch (DecryptException) {
            $this->discard($pickup, 'undecryptable');

            return;
        } catch (Throwable $exception) {
            Log::warning('Pickup buzz could not be attempted.', ['pickup_id' => $pickup->id, 'error' => class_basename($exception)]);
            $this->retry();

            return;
        }

        if ($status !== null && $status >= 200 && $status < 300) {
            return;
        }
        if (in_array($status, [400, 401, 403, 404, 410], true)) {
            $this->discard($pickup, 'status '.$status);

            return;
        }
        Log::warning('Pickup buzz delivery failed.', ['pickup_id' => $pickup->id, 'status' => $status]);
        if ($status === null || $status === 429 || $status >= 500) {
            $this->retry();
        }
    }

    private function retry(): void
    {
        if ($this->attempt < self::MAX_ATTEMPTS) {
            Bus::dispatch((new self($this->pickupTokenId, $this->attempt + 1))->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS)));
        }
    }

    private function discard(OrderPickupToken $pickup, string $reason): void
    {
        $pickup->pushSubscription()->delete();
        PickupNotifyChanged::dispatch($pickup->branch_id, $pickup->order_id);
        Log::info('Removed an unusable pickup push subscription.', ['pickup_id' => $pickup->id, 'reason' => $reason]);
    }
}
