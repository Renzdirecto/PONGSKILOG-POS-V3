<?php

use App\Events\CustomerCatalogChanged;
use App\Events\CustomerTrackingChanged;
use App\Events\QrOrderChanged;
use App\Models\Branch;
use App\Models\CustomerQrSession;
use App\Models\Order;
use App\Support\CustomerQrAccess;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

test('customer cookie authorizes only its narrow tracking and branch catalog channels', function () {
    $branch = Branch::factory()->create();
    $token = bin2hex(random_bytes(32));
    $session = CustomerQrSession::factory()->for($branch)->create(['token_hash' => hash('sha256', $token)]);
    $order = Order::factory()->for($branch)->create(['source' => 'customer_qr', 'customer_qr_session_id' => $session->id, 'public_tracking_id' => bin2hex(random_bytes(32))]);
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app', 'options' => ['cluster' => 'ap1'],
    ]]);
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);
    foreach (['private-order-tracking.'.$order->public_tracking_id, 'private-qr-catalog.'.$branch->id] as $channel) {
        $this->postJson(route('qr.broadcasting.auth', $branch), ['channel_name' => $channel, 'socket_id' => '1.2'])->assertOk()->assertJsonStructure(['auth'])->assertJsonMissingPath('user_id');
    }
    foreach (['private-branch.'.$branch->id.'.pos', 'private-branch.'.$branch->id.'.kitchen', 'private-branch.'.$branch->id.'.display', 'private-branch.'.$branch->id.'.inventory', 'private-qr-catalog.'.Branch::factory()->create()->id] as $channel) {
        $this->postJson(route('qr.broadcasting.auth', $branch), ['channel_name' => $channel, 'socket_id' => '1.2'])->assertForbidden();
    }
    $this->postJson(route('qr.broadcasting.auth', $branch), ['channel_name' => 'private-order-tracking.not-valid', 'socket_id' => '1.2'])->assertNotFound();
});

test('QR signals are synchronous only after outer commit and reveal minimal audience specific data', function () {
    $branch = Branch::factory()->create();
    $order = Order::factory()->for($branch)->create(['source' => 'customer_qr', 'public_tracking_id' => bin2hex(random_bytes(32)), 'submitted_at' => now()]);
    $signals = [new QrOrderChanged($order, 'qr.order_submitted'), new CustomerTrackingChanged($order), new CustomerCatalogChanged($branch->id)];
    $delivered = [];
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->times(3)->andReturnUsing(function ($channels, $name, $payload) use (&$delivered): void {
        $delivered[] = ['channels' => $channels, 'name' => $name, 'payload' => $payload];
    });
    Broadcast::extend('qr-test', fn () => $broadcaster);
    config(['broadcasting.default' => 'qr-test', 'broadcasting.connections.qr-test' => ['driver' => 'qr-test']]);
    Queue::fake();
    DB::transaction(function () use ($signals, &$delivered): void {
        DB::transaction(function () use ($signals): void {
            foreach ($signals as $signal) {
                event($signal);
            }
        });
        expect($delivered)->toBe([]);
    });
    expect(array_column($delivered, 'name'))->toBe(['qr.order_submitted', 'order.tracking_changed', 'qr.catalog_changed']);
    expect((string) $delivered[0]['channels'][0])->toBe('private-branch.'.$branch->id.'.pos');
    expect((string) $delivered[1]['channels'][0])->toBe('private-order-tracking.'.$order->public_tracking_id);
    expect($delivered[1]['payload'])->not->toHaveKeys(['order_id', 'branch_id', 'customer_label', 'items', 'payments', 'on_hand', 'token_hash']);
    expect($delivered[2]['payload'])->not->toHaveKeys(['on_hand', 'effective_price', 'user_id', 'items']);
    Queue::assertNothingPushed();
    DB::beginTransaction();
    foreach ($signals as $signal) {
        event($signal);
    }
    DB::rollBack();
    expect($delivered)->toHaveCount(3);
});

test('QR transport failure reports the outage without undoing committed state', function () {
    $branch = Branch::factory()->create();
    $order = Order::factory()->for($branch)->create(['source' => 'customer_qr', 'public_tracking_id' => bin2hex(random_bytes(32)), 'submitted_at' => now()]);
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->times(2)->andThrow(new RuntimeException('QR transport unavailable'));
    Broadcast::extend('qr-test', fn () => $broadcaster);
    config(['broadcasting.default' => 'qr-test', 'broadcasting.connections.qr-test' => ['driver' => 'qr-test']]);
    Exceptions::fake();
    DB::transaction(function () use ($order): void {
        $order->update(['commercial_status' => 'archived_unclaimed', 'archived_at' => now(), 'archive_reason' => 'cashier_archived']);
        QrOrderChanged::dispatch($order, 'qr.order_archived');
        CustomerTrackingChanged::dispatch($order);
    });
    expect($order->fresh()->archive_reason)->toBe('cashier_archived');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'QR transport unavailable');
});
