<?php

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
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
        ->and($edited->fresh()->commercial_status->value)->toBe('voided');
    $this->assertDatabaseHas('inventory_movements', [
        'order_id' => $edited->id,
        'movement_type' => 'void_restore',
        'quantity_delta' => 2,
    ]);
    expect($edited->inventoryMovements()->where('movement_type', 'void_restore')->count())->toBe(1);
    $this->assertDatabaseCount('order_voids', 1);
});
