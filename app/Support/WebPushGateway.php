<?php

namespace App\Support;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * The Web Push protocol through `minishlink/web-push`: VAPID authentication (RFC 8292) and RFC 8291 payload
 * encryption are the library's, never re-implemented here. HTTP goes through the PSR-18 client it discovers (Guzzle).
 *
 * The VAPID configuration is validated when the gateway is built, so a misconfiguration fails the delivery job as a
 * whole instead of looking like a problem with individual browser subscriptions.
 */
class WebPushGateway implements PushGateway
{
    private WebPush $webPush;

    public function __construct(?ClientInterface $client = null)
    {
        $vapid = PushNotifications::vapid() ?? throw new RuntimeException('Web Push is not configured.');
        $this->webPush = new WebPush(['VAPID' => $vapid], client: $client);
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
