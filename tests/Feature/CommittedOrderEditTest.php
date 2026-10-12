<?php

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\BranchTable;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
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
    expect(AuditLog::query()->where('action', 'committed_order_edited')->count())->toBe(1);
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

test('take out edits retain an optional active table', function () {
    $table = BranchTable::factory()->for($this->branch)->create(['name' => 'Table 3']);
    $order = committedEditFixture($this);
    $item = $order->items()->sole();

    $edited = app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, [
        'idempotency_key' => (string) Str::uuid(),
        'expected_version' => $order->version,
        'order_type' => 'take_out',
        'customer_label' => 'Ana',
        'branch_table_id' => $table->id,
        'items' => [[
            'existing_order_item_id' => $item->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'notes' => null,
            'modifiers' => [],
        ]],
    ]);

    expect($edited->order_type->value)->toBe('take_out')
        ->and($edited->branch_table_id)->toBe($table->id)
        ->and($edited->table_name_snapshot)->toBe('Table 3');
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
    expect(AuditLog::query()->where('action', 'committed_order_edited')->count())->toBe(0);
})->with(['stale', 'closed']);

test('History size edits preserve committed pricing and recompute changed sizes with exact balances and refunds', function () {
    $this->product->update(['name' => 'Lemon Yakult', 'default_price' => '50.00']);
    $group = ModifierGroup::factory()->create(['name' => 'Size', 'semantic_role' => 'size', 'min_select' => 1]);
    $medium = ModifierOption::factory()->for($group)->create(['name' => 'Medium', 'price_delta' => '10.00']);
    $large = ModifierOption::factory()->for($group)->create(['name' => 'Large', 'price_delta' => '20.00']);
    $this->product->modifierGroups()->attach($group);
    $draft = app(CreatePosDraftOrder::class)->execute($this->cashier, $this->branch, [
        'order_type' => 'take_out', 'items' => [[
            'product_id' => $this->product->id, 'quantity' => 1, 'modifiers' => [['group_id' => $group->id, 'option_id' => $medium->id]],
        ]],
    ]);
    $order = app(CommitPayLaterOrder::class)->execute($this->cashier, $this->branch, $draft, ['idempotency_key' => (string) Str::uuid()]);
    app(SettlePayLaterOrder::class)->execute($this->cashier, $this->branch, $order, [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '60.00', 'cashless_amount' => null,
    ]);
    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id]);
    $this->getJson(route('pos.transactions.show', $order))->assertOk()
        ->assertJsonPath('transaction.total', '60.00')->assertJsonPath('transaction.items.0.unit_price', '50.00')
        ->assertJsonPath('transaction.items.0.modifiers.0.price_delta', '10.00')->assertJsonPath('transaction.items.0.line_total', '60.00');
    $edit = function (ModifierOption $option) use ($order, $group) {
        $current = $order->fresh();

        return $this->patchJson(route('pos.transactions.update', $order), [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => $current->version, 'order_type' => 'take_out',
            'items' => [[
                'existing_order_item_id' => $current->items()->sole()->id, 'product_id' => $this->product->id, 'quantity' => 1,
                'unit_price' => '0.00', 'modifiers' => [['group_id' => $group->id, 'option_id' => $option->id, 'price_delta' => '0.00']],
            ]],
        ])->assertOk();
    };

    $this->product->update(['default_price' => '55.00']);
    $medium->update(['price_delta' => '15.00']);
    $edit($medium)->assertJsonPath('transaction.total', '60.00')->assertJsonPath('transaction.outstanding', '0.00');
    expect($order->adjustments()->count())->toBe(0);
    $edit($large)->assertJsonPath('transaction.total', '75.00')->assertJsonPath('transaction.outstanding', '15.00');
    app(SettlePayLaterOrder::class)->execute($this->cashier, $this->branch, $order->fresh(), [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '15.00', 'cashless_amount' => null,
    ]);
    $edit($medium)->assertJsonPath('transaction.total', '70.00')->assertJsonPath('transaction.outstanding', '0.00')
        ->assertJsonPath('transaction.adjustment_total', '5.00');
    $edit($medium)->assertJsonPath('transaction.total', '70.00')->assertJsonPath('transaction.adjustment_total', '5.00');

    expect($order->payments()->count())->toBe(2)->and($order->payments()->sum('amount'))->toEqual(75)
        ->and($order->adjustments()->sole()->cash_amount)->toBe('5.00')
        ->and($this->balance->fresh()->on_hand)->toBe(9);
});
