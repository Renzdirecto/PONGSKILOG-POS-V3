<?php

namespace App\Support;

use App\Models\PushSubscription;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one message to each subscription and keeps the subscription table clean:
 *
 * - 404/410 (expired or unsubscribed browser) and 400/401/403 (malformed, or made for another VAPID key) delete the
 *   subscription: the push service will never accept it again;
 * - a subscription whose stored material can no longer be decrypted (the application key changed) is deleted;
 * - 429, 5xx, transport failures (no response) and unexpected local errors are transient: their ids are returned for
 *   a bounded retry and the subscription is kept.
 *
 * Logs carry the subscription id, message type and status or error class only — never the endpoint (a secret
 * capability URL), the keys, the payload or an exception or push-service reason text that could contain them.
 */
class WebPushSender
{
    public function __construct(private PushGateway $gateway) {}

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<int> subscription ids worth retrying
     */
    public function send(PushMessage $message, Collection $subscriptions, ?string $branchName): array
    {
        $payload = json_encode($message->payload($branchName), JSON_THROW_ON_ERROR);
        $retry = [];

        foreach ($subscriptions as $subscription) {
            try {
                $status = $this->gateway->deliver($subscription, $message, $payload);
            } catch (DecryptException) {
                $this->discard($subscription, $message, 'undecryptable');

                continue;
            } catch (Throwable $exception) {
                $retry[] = (int) $subscription->getKey();
                Log::warning('Web push delivery could not be attempted.', [
                    'subscription_id' => $subscription->getKey(),
                    'type' => $message->type->value,
                    'error' => class_basename($exception),
                ]);

                continue;
            }

            if ($status !== null && $status >= 200 && $status < 300) {
                continue;
            }

            if (in_array($status, [400, 401, 403, 404, 410], true)) {
                $this->discard($subscription, $message, 'status '.$status);

                continue;
            }

            if ($status === null || $status === 429 || $status >= 500) {
                $retry[] = (int) $subscription->getKey();
            }

            Log::warning('Web push delivery failed.', [
                'subscription_id' => $subscription->getKey(),
                'type' => $message->type->value,
                'status' => $status,
            ]);
        }

        return $retry;
    }

    private function discard(PushSubscription $subscription, PushMessage $message, string $reason): void
    {
        $subscription->delete();

        Log::info('Removed an unusable web push subscription.', [
            'subscription_id' => $subscription->getKey(),
            'type' => $message->type->value,
            'reason' => $reason,
        ]);
    }
}
