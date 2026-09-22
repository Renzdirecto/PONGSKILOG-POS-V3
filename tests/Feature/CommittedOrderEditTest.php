<?php

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->cashier = User::factory()->create();
    $this->cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $this->cashier->branches()->attach($this->branch, ['is_active' => true]);
    $this->session = StoreSession::factory()->for($this->branch)->create(['opened_by_user_id' => $this->cashier->id]);
    $this->product = Product::factory()->create(['default_price' => '100.00']);
    BranchProduct::factory()->for($this->branch)->for($this->product)->create(['tracks_inventory' => true]);
    $this->balance = BranchInventory::factory()->for($this->branch)->for($this->product)->create(['on_hand' => 10]);
});

function committedEditFixture(object $test, int $quantity = 1): Order
{
    $draft = app(CreatePosDraftOrder::class)->execute($test->cashier, $test->branch, [
        'order_type' => 'take_out', 'customer_label' => 'Ana',
        'items' => [['product_id' => $test->product->id, 'quantity' => $quantity, 'notes' => null, 'modifiers' => []]],
    ]);

    return app(CommitPayLaterOrder::class)->execute($test->cashier, $test->branch, $draft, ['idempotency_key' => (string) Str::uuid()]);
}

test('edit preserves retained snapshots and applies one net inventory delta idempotently', function () {
    $order = committedEditFixture($this);
    $item = $order->items()->with('modifiers')->sole();
    $this->product->update(['default_price' => '150.00']);
    $key = (string) Str::uuid();
    $input = [
        'idempotency_key' => $key, 'expected_version' => $order->version, 'order_type' => 'take_out',
        'customer_label' => 'Ana', 'branch_table_id' => null, 'reason' => 'Added one meal',
        'items' => [['existing_order_item_id' => $item->id, 'product_id' => $this->product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]],
    ];

    $edited = app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, $input);
    expect($edited->total)->toBe('200.00')->and($edited->version)->toBe(3)
        ->and($edited->payment_status->value)->toBe('unpaid')->and($edited->payments()->count())->toBe(0)
        ->and($edited->kitchenTicket()->count())->toBe(1)->and($this->balance->fresh()->on_hand)->toBe(8);
    $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'movement_type' => 'order_edit_delta', 'quantity_delta' => -1]);
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $order->id, 'action' => 'committed_order_edited', 'idempotency_key' => strtolower($key)]);

    $replay = app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, $input);
    expect($replay->version)->toBe(3)->and($this->balance->fresh()->on_hand)->toBe(8);
    $this->assertDatabaseCount('audit_logs', 1);
});

test('same-total edit creates no money or inventory side effect', function () {
    $order = committedEditFixture($this);
    $item = $order->items()->sole();
    $edited = app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, [
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->version,
        'order_type' => 'take_out', 'customer_label' => 'Updated label', 'branch_table_id' => null,
        'items' => [['existing_order_item_id' => $item->id, 'product_id' => $this->product->id, 'quantity' => 1, 'notes' => 'No salt', 'modifiers' => []]],
    ]);

    expect($edited->total)->toBe('100.00')->and($edited->payments()->count())->toBe(0)
        ->and($edited->adjustments()->count())->toBe(0)->and($this->balance->fresh()->on_hand)->toBe(9);
    expect($edited->inventoryMovements()->where('movement_type', 'order_edit_delta')->count())->toBe(0);
});

test('product swap writes one compensating delta for each tracked product', function () {
    $order = committedEditFixture($this, 2);
    $item = $order->items()->sole();
    $replacement = Product::factory()->create(['default_price' => '125.00']);
    BranchProduct::factory()->for($this->branch)->for($replacement)->create(['tracks_inventory' => true]);
    $replacementBalance = BranchInventory::factory()->for($this->branch)->for($replacement)->create(['on_hand' => 5]);

    app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, [
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->version,
        'order_type' => 'take_out', 'customer_label' => 'Ana', 'branch_table_id' => null,
        'items' => [
            ['existing_order_item_id' => $item->id, 'product_id' => $this->product->id, 'quantity' => 1, 'notes' => null, 'modifiers' => []],
            ['product_id' => $replacement->id, 'quantity' => 1, 'notes' => null, 'modifiers' => []],
        ],
    ]);

    expect($this->balance->fresh()->on_hand)->toBe(9)->and($replacementBalance->fresh()->on_hand)->toBe(4);
    $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'product_id' => $this->product->id, 'movement_type' => 'order_edit_delta', 'quantity_delta' => 1]);
    $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'product_id' => $replacement->id, 'movement_type' => 'order_edit_delta', 'quantity_delta' => -1]);
});

test('paid lower-total edit appends an adjustment while preserving payment rows', function () {
    $order = committedEditFixture($this, 2);
    app(SettlePayLaterOrder::class)->execute($this->cashier, $this->branch, $order, [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '200.00', 'cashless_amount' => null,
    ]);
    $paymentId = $order->payments()->sole()->id;
    $item = $order->items()->with('modifiers')->sole();

    $edited = app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order->fresh(), [
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => 3, 'order_type' => 'take_out',
        'customer_label' => 'Ana', 'branch_table_id' => null,
        'items' => [['existing_order_item_id' => $item->id, 'product_id' => $this->product->id, 'quantity' => 1, 'notes' => null, 'modifiers' => []]],
    ]);

    expect($edited->total)->toBe('100.00')->and($edited->payment_status->value)->toBe('paid')
        ->and($edited->payments()->sole()->id)->toBe($paymentId)
        ->and($edited->adjustments()->sole()->amount)->toBe('100.00')
        ->and($this->balance->fresh()->on_hand)->toBe(9);
});

test('stale and closed-session edits are rejected without side effects', function (string $case) {
    $order = committedEditFixture($this);
    $item = $order->items()->sole();
    if ($case === 'closed') {
        $this->session->update(['status' => 'closed']);
    }
    $input = [
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => $case === 'stale' ? 1 : $order->version,
        'order_type' => 'take_out', 'customer_label' => null, 'branch_table_id' => null,
        'items' => [['existing_order_item_id' => $item->id, 'product_id' => $this->product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]],
    ];
    try {
        app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, $input);
        $this->fail('Expected edit rejection.');
    } catch (HttpException|ValidationException $exception) {
        expect($exception)->not->toBeNull();
    }
    expect($order->fresh()->total)->toBe('100.00')->and($this->balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['stale', 'closed']);
