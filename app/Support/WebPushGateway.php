<?php

namespace App\Support;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * The Web Push protocol through `minishlink/web-push`: VAPID authentication (RFC 8292) and RFC 8291 payload
 * encryption are the library's, never re-implemented here. HTTP goes through Guzzle with bounded timeouts, so a
 * stalled push service fails that one delivery (retried as transient) instead of holding the queue worker.
 *
 * The VAPID configuration is validated when the gateway is built, so a misconfiguration fails the delivery job as a
 * whole instead of looking like a problem with individual browser subscriptions. The library's environment checks
 * (for example a missing optional math extension) go to the application log instead of being raised as errors.
 */
class WebPushGateway implements PushGateway
{
    public const TIMEOUT_SECONDS = 10;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    private WebPush $webPush;

    public function __construct(?ClientInterface $client = null)
    {
        $vapid = PushNotifications::vapid() ?? throw new RuntimeException('Web Push is not configured.');
        $this->webPush = new WebPush(
            ['VAPID' => $vapid],
            client: $client ?? new Client([
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            ]),
            logger: Log::driver(),
        );
        $this->webPush->setReuseVAPIDHeaders(true);
    }

    public function deliver(PushSubscription $subscription, PushMessage $message, string $payload): ?int
    {
        $report = $this->webPush->sendOneNotification(
            new Subscription($subscription->endpoint, $subscription->public_key, $subscription->auth_token, $subscription->content_encoding),
            $payload,
            [
                'TTL' => $message->type->timeToLive(),
                'urgency' => $message->type->urgency(),
                'topic' => $message->topic(),
            ],
        );

        return $report->getResponse()?->getStatusCode();
    }
}
