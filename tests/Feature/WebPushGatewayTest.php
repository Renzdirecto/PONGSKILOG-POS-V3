<?php

use App\Models\PushSubscription;
use App\Support\PushMessage;
use App\Support\WebPushGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/** Windows PHP builds need OPENSSL_CONF pointing at PHP's openssl.cnf before OpenSSL can create P-256 keys. */
function ellipticCurveKeysAvailable(): bool
{
    return @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) !== false;
}

test('sends an encrypted, VAPID-signed message through the push service and reports its status', function () {
    $vapid = VAPID::createVapidKeys();
    config(['services.webpush' => ['subject' => 'mailto:push@example.com', 'public_key' => $vapid['publicKey'], 'private_key' => $vapid['privateKey']]]);
    $history = [];
    $handler = HandlerStack::create(new MockHandler([new Response(201), new Response(410)]));
    $handler->push(Middleware::history($history));
    $gateway = new WebPushGateway(new Client(['handler' => $handler]));
    $subscription = PushSubscription::factory()->create();
    $message = PushMessage::kitchenNewOrder('branch-id', 'order-id');
    $payload = json_encode($message->payload('Main'), JSON_THROW_ON_ERROR);

    expect($gateway->deliver($subscription, $message, $payload))->toBe(201)
        ->and($gateway->deliver($subscription, $message, $payload))->toBe(410);

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe($subscription->endpoint)
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($request->getHeaderLine('TTL'))->toBe('600')
        ->and($request->getHeaderLine('Urgency'))->toBe('high')
        ->and($request->getHeaderLine('Topic'))->toBe($message->topic())
        ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid t=')
        ->and($request->getHeaderLine('Authorization'))->toContain('k='.$vapid['publicKey'])
        ->and((string) $request->getBody())->not->toContain('kitchen.new_order')
        ->and($request->getHeaderLine('Authorization'))->not->toContain($vapid['privateKey']);
})->skip(fn (): bool => ! ellipticCurveKeysAvailable(), 'PHP cannot create P-256 keys here (on Windows set OPENSSL_CONF to PHP\'s extras\ssl\openssl.cnf).');

test('a stalled push service cannot hold the queue worker, and library requirement warnings are logged, never thrown', function () {
    $vapid = VAPID::createVapidKeys();
    config(['services.webpush' => ['subject' => 'mailto:push@example.com', 'public_key' => $vapid['publicKey'], 'private_key' => $vapid['privateKey']]]);

    /** @var WebPush $webPush */
    $webPush = (fn () => $this->webPush)->call(app(WebPushGateway::class));
    $client = (fn () => $this->client)->call($webPush);
    $logger = (fn () => $this->logger)->call($webPush);

    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->getConfig('timeout'))->toBe(WebPushGateway::TIMEOUT_SECONDS)
        ->and($client->getConfig('connect_timeout'))->toBe(WebPushGateway::CONNECT_TIMEOUT_SECONDS)
        ->and($logger)->toBeInstanceOf(LoggerInterface::class);
})->skip(fn (): bool => ! ellipticCurveKeysAvailable(), 'PHP cannot create P-256 keys here (on Windows set OPENSSL_CONF to PHP\'s extras\ssl\openssl.cnf).');

test('a message topic is a stable, push-service safe replacement key', function () {
    $first = PushMessage::orderReady('branch-id', 'order-id');

    expect($first->topic())->toMatch('/\A[A-Za-z0-9_-]{1,32}\z/')
        ->and($first->topic())->toBe(PushMessage::orderReady('branch-id', 'order-id')->topic())
        ->and($first->topic())->not->toBe(PushMessage::orderReady('branch-id', 'other-order')->topic());
});
