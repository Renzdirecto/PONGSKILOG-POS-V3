<?php

use App\Enums\KitchenStatus;
use App\Events\DisplayOrdersChanged;
use App\Events\KitchenOrderUpdated;
use App\Events\KitchenStatusChanged;
use App\Events\KitchenTicketCreated;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

test('critical lifecycle signals broadcast after the response and only after the outer transaction commits', function () {
    $branch = Branch::factory()->create();
    $order = Order::factory()->for($branch)->create(['kitchen_status' => KitchenStatus::Kitchen, 'committed_at' => now()]);
    $ticket = KitchenTicket::factory()->for($order)->for($branch)->create();
    $events = [new KitchenTicketCreated($order, $ticket), new KitchenStatusChanged($order, KitchenStatus::Kitchen, KitchenStatus::Ready, now()), new KitchenOrderUpdated($order), new DisplayOrdersChanged($branch, now())];
    $delivered = [];
    $broadcaster = Mockery::mock(Broadcaster::class);
    /** The kitchen status change also invalidates Owner reports through the reports.changed signal. */
    $broadcaster->shouldReceive('broadcast')->times(5)->andReturnUsing(function ($channels, $name, $payload) use (&$delivered): void {
        $delivered[] = $name;
    });
    Broadcast::extend('kitchen-test', fn () => $broadcaster);
    config(['broadcasting.default' => 'kitchen-test', 'broadcasting.connections.kitchen-test' => ['driver' => 'kitchen-test']]);

    DB::transaction(function () use ($events, &$delivered): void {
        DB::transaction(function () use ($events): void {
            foreach ($events as $event) {
                event($event);
            }
        });
        expect($delivered)->toBe([]);
    });

    expect($delivered)->toBe([]);
    app(DeferredCallbackCollection::class)->invoke();
    expect($delivered)->toBe(['kitchen.ticket_created', 'kitchen.status_changed', 'reports.changed', 'kitchen.order_updated', 'display.orders_changed']);

    DB::beginTransaction();
    foreach ($events as $event) {
        event($event);
    }
    DB::rollBack();
    app(DeferredCallbackCollection::class)->invoke();
    expect($delivered)->toHaveCount(5);
});

test('a realtime transport failure is reported without undoing the committed write', function () {
    $branch = Branch::factory()->create();
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->andThrow(new RuntimeException('Reverb unavailable'));
    Broadcast::extend('kitchen-test', fn () => $broadcaster);
    config(['broadcasting.default' => 'kitchen-test', 'broadcasting.connections.kitchen-test' => ['driver' => 'kitchen-test']]);
    Exceptions::fake();

    DB::transaction(function () use ($branch): void {
        $branch->update(['name' => 'Committed despite transport failure']);
        DisplayOrdersChanged::dispatch($branch, now());
    });

    expect($branch->fresh()->name)->toBe('Committed despite transport failure');
    app(DeferredCallbackCollection::class)->invoke();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Reverb unavailable');
});
