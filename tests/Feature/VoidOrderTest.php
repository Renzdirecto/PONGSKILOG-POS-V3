<?php

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\VoidOrder;
use App\Events\AuditLogRecorded;
use App\Events\CustomerTrackingChanged;
use App\Events\DisplayOrdersChanged;
use App\Events\OrderVoided;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->cashier = User::factory()->create();
    $this->cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $this->cashier->branches()->attach($this->branch, ['is_active' => true]);
    $this->owner = User::factory()->create(['email' => 'owner@pongskilog.test']);
    $this->owner->roles()->attach(Role::query()->where('name', 'owner')->sole());
    $this->superAdmin = User::factory()->create(['email' => 'super-admin@pongskilog.test']);
    $this->superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    VoidAuthorizationSetting::query()->create([
        'pin_hash' => Hash::make('1234'),
        'configured_by_user_id' => $this->superAdmin->id,
        'configured_at' => now(),
    ]);
    $this->session = StoreSession::factory()->for($this->branch)->create(['opened_by_user_id' => $this->cashier->id]);
    $this->product = Product::factory()->create(['default_price' => '100.00']);
    BranchProduct::factory()->for($this->branch)->for($this->product)->create(['tracks_inventory' => true]);
    $this->balance = BranchInventory::factory()->for($this->branch)->for($this->product)->create(['on_hand' => 10]);
});

function voidOrderFixture(object $test, int $quantity = 1): Order
{
    $draft = app(CreatePosDraftOrder::class)->execute($test->cashier, $test->branch, [
        'order_type' => 'take_out',
        'customer_label' => 'Ana',
        'items' => [[
            'product_id' => $test->product->id,
            'quantity' => $quantity,
            'notes' => null,
            'modifiers' => [],
        ]],
    ]);

    return app(CommitPayLaterOrder::class)->execute($test->cashier, $test->branch, $draft, [
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

function voidPayload(object $test, Order $order, array $overrides = []): array
{
    return [
        'reason_code' => 'wrong_item',
        'reason_text' => null,
        'authorization_pin' => '1234',
        'idempotency_key' => (string) Str::uuid(),
        'expected_version' => $order->version,
        ...$overrides,
    ];
}

test('cashier voids a current-session pay later order with the configured super admin PIN', function () {
    $order = voidOrderFixture($this, 2);
    $payload = voidPayload($this, $order);

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->postJson(route('pos.transactions.void', $order), $payload)
        ->assertOk()
        ->assertJsonPath('transaction.commercial_status', 'voided')
        ->assertJsonPath('transaction.void.reason_code', 'wrong_item')
        ->assertJsonPath('transaction.can_void', false);

    expect($order->fresh()->commercial_status->value)->toBe('voided')
        ->and($order->fresh()->version)->toBe(3)
        ->and($this->balance->fresh()->on_hand)->toBe(10)
        ->and($order->payments()->count())->toBe(0)
        ->and($order->kitchenTicket()->count())->toBe(1);
    $this->assertDatabaseHas('order_voids', [
        'order_id' => $order->id,
        'initiated_by_user_id' => $this->cashier->id,
        'authorized_by_user_id' => $this->superAdmin->id,
        'reason_code' => 'wrong_item',
        'authorization_method' => 'super_admin_pin',
    ]);
    $this->assertDatabaseHas('inventory_movements', [
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'movement_type' => 'void_restore',
        'quantity_delta' => 2,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $order->id,
        'user_id' => $this->cashier->id,
        'action' => 'order.voided',
        'idempotency_key' => strtolower($payload['idempotency_key']),
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $order->id,
        'user_id' => $this->cashier->id,
        'action' => 'order.pay_later_committed',
    ]);
});

test('void rejects an incorrect configured PIN', function () {
    $order = voidOrderFixture($this);

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->postJson(route('pos.transactions.void', $order), voidPayload($this, $order, [
            'authorization_pin' => '9999',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['authorization']);

    expect($order->fresh()->commercial_status->value)->toBe('active')
        ->and($this->balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseCount('order_voids', 0);
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $order->id, 'action' => 'order.pay_later_committed']);
});

test('void fails safely without a configured PIN or with an ineligible PIN owner', function (string $state) {
    $order = voidOrderFixture($this);
    $setting = VoidAuthorizationSetting::query()->sole();

    match ($state) {
        'missing' => $setting->delete(),
        'inactive' => $this->superAdmin->forceFill(['is_active' => false])->save(),
        'role_removed' => $this->superAdmin->roles()->detach(),
    };

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->postJson(route('pos.transactions.void', $order), voidPayload($this, $order))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['authorization']);

    expect($order->fresh()->commercial_status->value)->toBe('active')
        ->and($this->balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseCount('order_voids', 0);
})->with(['missing', 'inactive', 'role_removed']);

test('void rejects a PIN configured by the initiating cashier even when they are also a super admin', function () {
    $order = voidOrderFixture($this);
    $this->cashier->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    VoidAuthorizationSetting::query()->sole()->update(['configured_by_user_id' => $this->cashier->id]);

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->postJson(route('pos.transactions.void', $order), voidPayload($this, $order))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['authorization']);

    expect($order->fresh()->commercial_status->value)->toBe('active');
    $this->assertDatabaseCount('order_voids', 0);
});

test('void rejects a blank other reason and never stores the authorization PIN', function () {
    $order = voidOrderFixture($this);
    $payload = voidPayload($this, $order, [
        'reason_code' => 'other',
        'reason_text' => '   ',
    ]);

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->postJson(route('pos.transactions.void', $order), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason_text');

    $payload = voidPayload($this, $order, [
        'reason_code' => 'other',
        'reason_text' => 'Customer changed their mind.',
    ]);
    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->postJson(route('pos.transactions.void', $order), $payload)
        ->assertOk();

    $audit = AuditLog::query()->where('auditable_id', $order->id)->where('action', 'order.voided')->sole();

    expect(json_encode($audit->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('1234');
});

test('void restores the net tracked inventory effect after an edited order and replays exactly once', function () {
    $order = voidOrderFixture($this);
    $item = $order->items()->sole();
    $edited = app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, [
        'idempotency_key' => (string) Str::uuid(),
        'expected_version' => $order->version,
        'order_type' => 'take_out',
        'customer_label' => 'Ana',
        'branch_table_id' => null,
        'items' => [[
            'existing_order_item_id' => $item->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'notes' => null,
            'modifiers' => [],
        ]],
    ]);
    $payload = voidPayload($this, $edited);

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($this->cashier)
            ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
            ->postJson(route('pos.transactions.void', $edited), $payload)
            ->assertOk();
    }

    expect($this->balance->fresh()->on_hand)->toBe(10)
        ->and($edited->fresh()->commercial_status->value)->toBe('voided')
        ->and($edited->fresh()->version)->toBe(4);
    $this->assertDatabaseHas('inventory_movements', [
        'order_id' => $edited->id,
        'movement_type' => 'void_restore',
        'quantity_delta' => 2,
    ]);
    expect($edited->inventoryMovements()->where('movement_type', 'void_restore')->count())->toBe(1);
    $this->assertDatabaseCount('order_voids', 1);
    expect(AuditLog::query()->where('action', 'order.voided')->count())->toBe(1);
});

test('reusing a void idempotency key with changed intent returns conflict', function () {
    $order = voidOrderFixture($this);
    $payload = voidPayload($this, $order);

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->postJson(route('pos.transactions.void', $order), $payload)
        ->assertOk();

    $this->postJson(route('pos.transactions.void', $order), [
        ...$payload,
        'reason_code' => 'customer_cancelled',
    ])->assertConflict();

    $this->assertDatabaseCount('order_voids', 1);
    expect(AuditLog::query()->where('action', 'order.voided')->count())->toBe(1);
});

test('void rolls back every critical effect when a critical write fails', function (string $_stage, string $trigger): void {
    $order = voidOrderFixture($this);
    Event::fake([AuditLogRecorded::class, CustomerTrackingChanged::class, DisplayOrdersChanged::class, OrderVoided::class]);
    DB::statement($trigger);

    expect(fn () => app(VoidOrder::class)->execute(
        $this->cashier,
        $this->branch,
        $order,
        voidPayload($this, $order),
    ))->toThrow(QueryException::class);

    expect($order->fresh()->commercial_status->value)->toBe('active')
        ->and($order->fresh()->version)->toBe(2)
        ->and($this->balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseCount('order_voids', 0);
    expect($order->inventoryMovements()->where('movement_type', 'void_restore')->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'order.voided')->count())->toBe(0);
    Event::assertNotDispatched(AuditLogRecorded::class);
    Event::assertNotDispatched(CustomerTrackingChanged::class);
    Event::assertNotDispatched(DisplayOrdersChanged::class);
    Event::assertNotDispatched(OrderVoided::class);
})->with([
    'inventory restoration' => ['inventory', "CREATE TRIGGER fail_void_inventory BEFORE INSERT ON inventory_movements WHEN NEW.movement_type = 'void_restore' BEGIN SELECT RAISE(FAIL, 'injected inventory failure'); END"],
    'OrderVoid creation' => ['void', "CREATE TRIGGER fail_order_void BEFORE INSERT ON order_voids BEGIN SELECT RAISE(FAIL, 'injected void failure'); END"],
    'Order state update' => ['order', "CREATE TRIGGER fail_void_order_update BEFORE UPDATE ON orders WHEN NEW.commercial_status = 'voided' BEGIN SELECT RAISE(FAIL, 'injected order failure'); END"],
    'audit creation' => ['audit', "CREATE TRIGGER fail_void_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'order.voided' BEGIN SELECT RAISE(FAIL, 'injected audit failure'); END"],
]);
