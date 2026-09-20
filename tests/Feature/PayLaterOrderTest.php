<?php

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\BranchTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** @return array{Branch, User, Product, BranchInventory, StoreSession, Order} */
function payLaterFixture(string $role = 'cashier', string $price = '235.00', int $stock = 10): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    $session = StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['default_price' => $price]);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $stock, 'version' => 3]);
    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, [
        'order_type' => 'take_out',
        'customer_label' => null,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => 'Less salt', 'modifiers' => []]],
    ]);

    return [$branch, $user, $product, $balance, $session, $order];
}

test('cashier commits an existing draft as unpaid pay later with inventory and kitchen exactly once', function (string $role) {
    [$branch, $user, $product, $balance, $session, $order] = payLaterFixture($role);
    $number = $order->order_number;
    $reference = $order->reference_number;
    $key = (string) Str::uuid();

    $response = $this->actingAs($user)->postJson(route('pos.orders.pay-later.store', $order), [
        'idempotency_key' => $key,
    ])->assertOk();

    $order->refresh();
    $response->assertJsonPath('order.id', $order->id)
        ->assertJsonPath('order.order_number', $number)
        ->assertJsonPath('order.reference_number', $reference)
        ->assertJsonPath('order.commercial_status', 'active')
        ->assertJsonPath('order.payment_status', 'unpaid')
        ->assertJsonPath('order.payment_term', 'pay_later')
        ->assertJsonPath('order.kitchen_status', 'kitchen')
        ->assertJsonPath('order.customer_label', null)
        ->assertJsonPath('order.items.0.notes', 'Less salt');
    expect($order->commercial_status)->toBe(CommercialStatus::Active)
        ->and($order->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment_term)->toBe(PaymentTerm::PayLater)
        ->and($order->kitchen_status)->toBe(KitchenStatus::Kitchen)
        ->and($order->store_session_id)->toBe($session->id)
        ->and($order->committed_at)->not->toBeNull()
        ->and($order->pay_later_idempotency_key)->toBe(strtolower($key))
        ->and($order->version)->toBe(2)
        ->and($order->order_number)->toBe($number)
        ->and($order->reference_number)->toBe($reference)
        ->and($balance->fresh()->on_hand)->toBe(9)
        ->and($balance->fresh()->version)->toBe(4);
    $this->assertDatabaseHas('inventory_movements', [
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'order_id' => $order->id,
        'movement_type' => 'pay_later_commit',
        'quantity_delta' => -1,
    ]);
    $this->assertDatabaseHas('kitchen_tickets', [
        'branch_id' => $branch->id,
        'order_id' => $order->id,
        'status' => 'kitchen',
    ]);
    $this->assertDatabaseCount('payments', 0);

    $this->postJson(route('pos.orders.pay-later.store', $order), ['idempotency_key' => $key])
        ->assertExactJson($response->json());
    expect($balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('payments', 0);
})->with(['cashier', 'cashier_kitchen']);

test('repeated product lines aggregate into one movement and untracked products do not move stock', function () {
    [$branch, $user, $product, $balance] = payLaterFixture();
    $untracked = Product::factory()->create(['default_price' => '10.00']);
    BranchProduct::factory()->for($branch)->for($untracked)->create(['tracks_inventory' => false]);
    $untrackedBalance = BranchInventory::factory()->for($branch)->for($untracked)->create(['on_hand' => 77, 'version' => 8]);
    $draft = app(CreatePosDraftOrder::class)->execute($user, $branch, [
        'order_type' => 'dine_in',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []],
            ['product_id' => $product->id, 'quantity' => 2, 'modifiers' => []],
            ['product_id' => $untracked->id, 'quantity' => 5, 'modifiers' => []],
        ],
    ]);

    app(CommitPayLaterOrder::class)->execute($user, $branch, $draft, ['idempotency_key' => (string) Str::uuid()]);

    expect($balance->fresh()->on_hand)->toBe(7)
        ->and($untrackedBalance->fresh()->on_hand)->toBe(77);
    $this->assertDatabaseHas('inventory_movements', ['order_id' => $draft->id, 'product_id' => $product->id, 'quantity_delta' => -3]);
    $this->assertDatabaseMissing('inventory_movements', ['order_id' => $draft->id, 'product_id' => $untracked->id]);
    $this->assertDatabaseCount('payments', 0);
});

test('Pay Later inventory reads stay bounded as repeated cart lines grow', function () {
    $readCounts = [];

    foreach ([1, 30, 100] as $lineCount) {
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
        $user->branches()->attach($branch, ['is_active' => true]);
        StoreSession::factory()->for($branch)->create();
        $product = Product::factory()->create();
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
        $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 200]);
        $line = ['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []];
        $order = app(CreatePosDraftOrder::class)->execute($user, $branch, [
            'order_type' => 'take_out',
            'items' => array_fill(0, $lineCount, $line),
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid()]);
        $readCounts[] = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))
            ->count();
        DB::disableQueryLog();

        expect($balance->fresh()->on_hand)->toBe(200 - $lineCount);
        $this->assertDatabaseHas('inventory_movements', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_delta' => -$lineCount,
        ]);
        expect($order->inventoryMovements()->count())->toBe(1);
    }

    expect(array_unique($readCounts))->toHaveCount(1);
});

test('a different key after commit and a reused key on another order are rejected without duplicate effects', function () {
    [$branch, $user, $product, $balance, , $order] = payLaterFixture();
    $key = (string) Str::uuid();
    $this->actingAs($user)->postJson(route('pos.orders.pay-later.store', $order), ['idempotency_key' => $key])->assertOk();

    $this->postJson(route('pos.orders.pay-later.store', $order), ['idempotency_key' => (string) Str::uuid()])
        ->assertConflict();
    $other = app(CreatePosDraftOrder::class)->execute($user, $branch, [
        'order_type' => 'take_out',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]],
    ]);
    $this->postJson(route('pos.orders.pay-later.store', $other), ['idempotency_key' => $key])
        ->assertConflict();

    expect($balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('payments', 0);
});

test('commit revalidates current catalog stock and table without repricing the draft', function (string $invalid) {
    [$branch, $user, $product, $balance, , $order] = payLaterFixture();
    $table = BranchTable::factory()->for($branch)->create();
    $order->update(['branch_table_id' => $table->id]);
    $product->update(['default_price' => '999.00']);
    match ($invalid) {
        'product' => $product->update(['is_active' => false]),
        'category' => $product->category()->update(['is_active' => false]),
        'availability' => BranchProduct::query()->whereBelongsTo($branch)->whereBelongsTo($product)->update(['is_available' => false]),
        'stock' => $balance->update(['on_hand' => 0]),
        'table' => $table->update(['is_active' => false]),
    };

    $this->actingAs($user)->postJson(route('pos.orders.pay-later.store', $order), ['idempotency_key' => (string) Str::uuid()])
        ->assertUnprocessable();

    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::Draft)
        ->and($order->fresh()->total)->toBe('235.00')
        ->and($order->fresh()->committed_at)->toBeNull();
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('kitchen_tickets', 0);
})->with(['product', 'category', 'availability', 'stock', 'table']);

test('closed store and kitchen failure roll back the entire pay later commit', function (string $failure) {
    [, $user, , $balance, $session, $order] = payLaterFixture();
    if ($failure === 'store') {
        $session->update(['status' => 'closed']);
    } else {
        DB::unprepared("CREATE TRIGGER fail_pay_later_ticket BEFORE INSERT ON kitchen_tickets BEGIN SELECT RAISE(ABORT, 'injected ticket failure'); END");
    }

    try {
        $response = $this->actingAs($user)->postJson(route('pos.orders.pay-later.store', $order), ['idempotency_key' => (string) Str::uuid()]);
        if ($failure === 'store') {
            $response->assertUnprocessable()->assertJsonValidationErrors('store');
        } else {
            $response->assertServerError();
        }
    } finally {
        if ($failure === 'kitchen') {
            DB::unprepared('DROP TRIGGER fail_pay_later_ticket');
        }
    }

    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::Draft)
        ->and($order->fresh()->committed_at)->toBeNull()
        ->and($balance->fresh()->on_hand)->toBe(10);
    foreach (['payments', 'inventory_movements', 'kitchen_tickets'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['store', 'kitchen']);

test('foreign orders and non cashier roles cannot activate pay later', function (string $case) {
    [$branch, $cashier, , , , $order] = payLaterFixture();
    $user = $cashier;
    $activeBranch = $branch;
    if ($case === 'foreign') {
        $activeBranch = Branch::factory()->create();
        StoreSession::factory()->for($activeBranch)->create();
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
        $user->branches()->attach($activeBranch, ['is_active' => true]);
    } else {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $case)->sole());
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    $response = $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $activeBranch->id])
        ->postJson(route('pos.orders.pay-later.store', $order), ['idempotency_key' => (string) Str::uuid()]);
    $case === 'foreign' ? $response->assertNotFound() : $response->assertForbidden();
    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::Draft);
})->with(['foreign', 'kitchen_staff', 'owner', 'super_admin']);

test('persisted access changes reject activation', function (string $change) {
    [$branch, $user, , , , $order] = payLaterFixture();
    $user->load('roles', 'branches');
    match ($change) {
        'user' => User::query()->whereKey($user->id)->update(['is_active' => false]),
        'assignment' => $user->branches()->updateExistingPivot($branch->id, ['is_active' => false]),
        'permission' => Role::query()->where('name', 'cashier')->sole()->permissions()->detach(),
        'inactive' => $branch->update(['status' => BranchStatus::Inactive]),
        'temporary' => $branch->update(['status' => BranchStatus::TemporarilyClosed]),
    };

    expect(fn () => app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid()]))
        ->toThrow(AuthorizationException::class);
    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::Draft);
})->with(['user', 'assignment', 'permission', 'inactive', 'temporary']);

test('forged server owned pay later fields are rejected', function (string $field) {
    [, $user, , , , $order] = payLaterFixture();

    $this->actingAs($user)->postJson(route('pos.orders.pay-later.store', $order), [
        'idempotency_key' => (string) Str::uuid(),
        $field => $field === 'total' ? '0.01' : 'forged',
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::Draft);
})->with(['branch_id', 'store_session_id', 'subtotal', 'total', 'stock_amount', 'line_price', 'commercial_status', 'payment_status', 'payment_term', 'kitchen_status', 'order_number', 'reference_number']);

test('one Pay Later request converts the current reservation and preserves its identity', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 5]);
    $reservation = $this->actingAs($user)->postJson(route('pos.orders.reservations.store'), ['order_type' => 'take_out'])
        ->assertOk()->json('order');
    $orderCount = Order::query()->count();
    $key = (string) Str::uuid();
    $payload = [
        'idempotency_key' => $key,
        'order_type' => 'take_out',
        'customer_label' => 'Alex',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]],
    ];

    $response = $this->postJson(route('pos.orders.pay-later.store', $reservation['id']), $payload)
        ->assertOk()
        ->assertJsonPath('order.id', $reservation['id'])
        ->assertJsonPath('order.order_number', $reservation['order_number'])
        ->assertJsonPath('order.reference_number', $reservation['reference_number'])
        ->assertJsonPath('order.commercial_status', 'active')
        ->assertJsonPath('order.payment_status', 'unpaid')
        ->assertJsonPath('order.payment_term', 'pay_later')
        ->assertJsonPath('order.kitchen_status', 'kitchen')
        ->assertJsonPath('order.customer_label', 'Alex');

    $order = Order::query()->findOrFail($reservation['id']);
    expect(Order::query()->count())->toBe($orderCount)
        ->and($order->commercial_status)->toBe(CommercialStatus::Active)
        ->and($order->store_session_id)->not->toBeNull()
        ->and($order->committed_at)->not->toBeNull()
        ->and($balance->fresh()->on_hand)->toBe(4);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('payments', 0);

    $this->postJson(route('pos.orders.pay-later.store', $reservation['id']), $payload)
        ->assertExactJson($response->json());
    expect($balance->fresh()->on_hand)->toBe(4);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('payments', 0);

    $next = $this->postJson(route('pos.orders.reservations.store'), ['order_type' => 'dine_in'])->assertOk()->json('order');
    expect($next['order_number'])->toBe((string) ((int) $reservation['order_number'] + 1));
    $again = $this->postJson(route('pos.orders.reservations.store'), ['order_type' => 'take_out'])->assertOk()->json('order');
    expect($again['id'])->toBe($next['id'])->and($again['order_number'])->toBe($next['order_number']);
});

test('a one-step Pay Later replay rejects changed cart details without repeating operational effects', function (string $change) {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    foreach ([$product, $otherProduct] as $availableProduct) {
        BranchProduct::factory()->for($branch)->for($availableProduct)->create(['tracks_inventory' => true]);
        BranchInventory::factory()->for($branch)->for($availableProduct)->create(['on_hand' => 5]);
    }
    $reservation = $this->actingAs($user)->postJson(route('pos.orders.reservations.store'), ['order_type' => 'take_out'])
        ->assertOk()->json('order');
    $payload = [
        'idempotency_key' => (string) Str::uuid(),
        'order_type' => 'take_out',
        'customer_label' => 'Alex',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => '', 'modifiers' => []]],
    ];
    $this->postJson(route('pos.orders.pay-later.store', $reservation['id']), $payload)->assertOk();

    match ($change) {
        'quantity' => $payload['items'][0]['quantity'] = 2,
        'product' => $payload['items'][0]['product_id'] = $otherProduct->id,
        'customer' => $payload['customer_label'] = 'Blake',
        'order type' => $payload['order_type'] = 'dine_in',
    };

    $this->postJson(route('pos.orders.pay-later.store', $reservation['id']), $payload)->assertConflict();

    $order = Order::query()->findOrFail($reservation['id']);
    expect($order->commercial_status)->toBe(CommercialStatus::Active)
        ->and($order->items()->sole()->product_id)->toBe($product->id)
        ->and($order->items()->sole()->quantity)->toBe(1)
        ->and($order->customer_label)->toBe('Alex');
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('payments', 0);
})->with(['quantity', 'product', 'customer', 'order type']);

test('a failed one-step Pay Later commit keeps the reservation and rolls back cart persistence', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 2]);
    $reservation = $this->actingAs($user)->postJson(route('pos.orders.reservations.store'), ['order_type' => 'dine_in'])
        ->assertOk()->json('order');
    DB::unprepared("CREATE TRIGGER fail_one_step_pay_later_ticket BEFORE INSERT ON kitchen_tickets BEGIN SELECT RAISE(ABORT, 'injected ticket failure'); END");

    try {
        $this->postJson(route('pos.orders.pay-later.store', $reservation['id']), [
            'idempotency_key' => (string) Str::uuid(),
            'order_type' => 'dine_in',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]],
        ])->assertServerError();
    } finally {
        DB::unprepared('DROP TRIGGER fail_one_step_pay_later_ticket');
    }

    $order = Order::query()->findOrFail($reservation['id']);
    expect($order->commercial_status)->toBe(CommercialStatus::Draft)
        ->and($order->committed_at)->toBeNull()
        ->and($order->items()->count())->toBe(0)
        ->and($balance->fresh()->on_hand)->toBe(2);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('kitchen_tickets', 0);
    $this->assertDatabaseCount('payments', 0);
});
