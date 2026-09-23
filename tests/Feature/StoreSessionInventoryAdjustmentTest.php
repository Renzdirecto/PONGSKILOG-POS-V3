<?php

use App\Actions\Audit\AuditRecorder;
use App\Actions\StoreSessions\RecordStoreSessionInventoryAdjustment;
use App\Events\CustomerCatalogChanged;
use App\Events\InventoryChanged;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSessionInventoryAdjustment;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->scenario = StoreCloseScenario::create('1000.00', '0.00');
    $this->scenario->product->update(['name' => 'Lemon Cola']);
    $this->scenario->stock->update(['on_hand' => 50]);
});

/** @param array<string, mixed> $overrides */
function inventoryAdjustment(StoreCloseScenario $scenario, array $overrides = [], ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? $scenario->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->postJson(route('store-session-inventory-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'reason_code' => 'complimentary',
            'product_id' => $scenario->product->id,
            'quantity' => 1,
            'note' => null,
            ...$overrides,
        ]);
}

test('a complimentary item deducts stock only, audits once and reuses inventory realtime', function () {
    $scenario = $this->scenario;
    $scenario->done($scenario->payNow(1, 'cash'));
    $scenario->expense('20.00', 'cash');
    $before = $scenario->reconciliation();
    Event::fake([InventoryChanged::class, CustomerCatalogChanged::class]);
    $payload = ['idempotency_key' => (string) Str::uuid(), 'note' => 'Free drink for delayed order'];

    inventoryAdjustment($scenario, $payload)->assertOk()
        ->assertJsonPath('adjustment.reason_label', 'Complimentary / Free item')
        ->assertJsonPath('adjustment.on_hand', 48)
        ->assertJsonPath('adjustment.quantity', 1);
    inventoryAdjustment($scenario, $payload)->assertOk();

    $adjustment = StoreSessionInventoryAdjustment::query()->sole();
    $movement = InventoryMovement::query()->whereKey($adjustment->inventory_movement_id)->sole();
    expect($scenario->stock->fresh()->on_hand)->toBe(48)
        ->and($adjustment->store_session_id)->toBe($scenario->session->id)
        ->and($adjustment->created_by_user_id)->toBe($scenario->cashier->id)
        ->and($movement->movement_type->value)->toBe('manual_adjustment')
        ->and($movement->quantity_delta)->toBe(-1)
        ->and($movement->reason)->toBe('Store Session adjustment: Complimentary / Free item — Free drink for delayed order')
        ->and($scenario->session->expenses()->count())->toBe(1)
        ->and($scenario->reconciliation())->toBe($before);
    $this->assertDatabaseCount('payments', 1);
    $audit = AuditLog::query()->where('action', 'store_session.inventory_adjusted')->sole();
    expect($audit->module)->toBe('inventory')
        ->and($audit->before)->toBe(['on_hand' => 49])
        ->and($audit->after)->toBe(['on_hand' => 48])
        ->and($audit->metadata)->toMatchArray(['store_session_id' => $scenario->session->id, 'product_name' => 'Lemon Cola', 'quantity_deducted' => 1, 'reason_code' => 'complimentary']);
    Event::assertDispatchedTimes(InventoryChanged::class, 1);
    Event::assertDispatchedTimes(CustomerCatalogChanged::class, 1);

    inventoryAdjustment($scenario, [...$payload, 'quantity' => 2])->assertConflict();
});

test('each predefined reason deducts stock', function (string $reason) {
    inventoryAdjustment($this->scenario, ['reason_code' => $reason, 'quantity' => 3])->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(47)
        ->and(StoreSessionInventoryAdjustment::query()->sole()->reason_code->value)->toBe($reason);
})->with(['wastage', 'damaged', 'staff_meal']);

test('Other requires an explanation', function () {
    inventoryAdjustment($this->scenario, ['reason_code' => 'other', 'note' => '   '])
        ->assertUnprocessable()->assertJsonValidationErrors(['note' => 'Explain the adjustment when the reason is Other.']);
    inventoryAdjustment($this->scenario, ['reason_code' => 'other', 'note' => 'Spilled during delivery'])->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(49);
});

test('invalid quantities and insufficient stock never change inventory', function (mixed $quantity, string $field) {
    inventoryAdjustment($this->scenario, ['quantity' => $quantity])->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
    $this->assertDatabaseCount('store_session_inventory_adjustments', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    'zero' => [0, 'quantity'],
    'negative' => [-1, 'quantity'],
    'fraction' => [1.5, 'quantity'],
    'more than stock' => [51, 'quantity'],
]);

test('products that are not tracked in the active branch are rejected', function () {
    $other = Branch::factory()->create();
    $foreign = Product::factory()->create();
    BranchProduct::factory()->for($other)->for($foreign)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($other)->for($foreign)->create(['on_hand' => 10]);

    inventoryAdjustment($this->scenario, ['product_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['product_id']);

    expect(BranchInventory::query()->where('product_id', $foreign->id)->value('on_hand'))->toBe(10);
});

test('a closed Store Session rejects adjustments', function () {
    $this->scenario->session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->scenario->cashier->id]);

    inventoryAdjustment($this->scenario)->assertUnprocessable()->assertJsonValidationErrors(['store']);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
});

test('roles without Store Session operations are rejected', function (string $role) {
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($this->scenario->branch, ['is_active' => true]);

    inventoryAdjustment($this->scenario, [], $user)->assertForbidden();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
})->with(['kitchen_staff', 'owner']);

test('a cashier with kitchen access may adjust inventory', function () {
    inventoryAdjustment($this->scenario, [], $this->scenario->user('cashier_kitchen'))->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(49);
});

test('guests, inactive users and unassigned cashiers cannot adjust the active branch inventory', function (string $case, int $status) {
    $scenario = $this->scenario;
    $request = match ($case) {
        'guest' => fn () => $this->postJson(route('store-session-inventory-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(), 'reason_code' => 'wastage', 'product_id' => $scenario->product->id, 'quantity' => 1,
        ]),
        'inactive user' => fn () => inventoryAdjustment($scenario, [], $scenario->user('cashier', active: false)),
        'inactive assignment' => fn () => inventoryAdjustment($scenario, [], $scenario->user('cashier', assignmentActive: false)),
        'other branch cashier' => fn () => inventoryAdjustment($scenario, [], $scenario->user('cashier', Branch::factory()->create())),
    };

    $request()->assertStatus($status);

    expect($scenario->stock->fresh()->on_hand)->toBe(50);
    $this->assertDatabaseCount('store_session_inventory_adjustments', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    'guest' => ['guest', 401],
    'inactive user' => ['inactive user', 401],
    'inactive assignment' => ['inactive assignment', 302],
    /** Branch context resolves to the cashier's own branch, which has no open Store Session. */
    'other branch cashier' => ['other branch cashier', 422],
]);

test('a failed adjustment write rolls back stock, movement and attribution without realtime', function (string $failure) {
    Event::fake([InventoryChanged::class, CustomerCatalogChanged::class]);
    match ($failure) {
        'adjustment record' => StoreSessionInventoryAdjustment::creating(fn () => throw new RuntimeException('Injected adjustment failure')),
        'audit' => app()->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(...$arguments): AuditLog
            {
                throw new RuntimeException('Injected audit failure');
            }
        }),
    };

    expect(fn () => app(RecordStoreSessionInventoryAdjustment::class)->execute($this->scenario->cashier, $this->scenario->branch, [
        'idempotency_key' => (string) Str::uuid(), 'reason_code' => 'damaged', 'product_id' => $this->scenario->product->id, 'quantity' => 2,
    ]))->toThrow(RuntimeException::class);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50)
        ->and($this->scenario->stock->fresh()->version)->toBe($this->scenario->stock->version);
    $this->assertDatabaseCount('store_session_inventory_adjustments', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    expect(AuditLog::query()->where('action', 'store_session.inventory_adjusted')->count())->toBe(0);
    Event::assertNotDispatched(InventoryChanged::class);
    Event::assertNotDispatched(CustomerCatalogChanged::class);
})->with(['adjustment record', 'audit']);

test('adjustments appear in the current-session projection without changing money totals', function () {
    inventoryAdjustment($this->scenario, ['reason_code' => 'wastage', 'quantity' => 2, 'note' => 'Dropped tray'])->assertOk();

    $this->actingAs($this->scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->scenario->branch->id])
        ->getJson(route('store-sessions.current'))->assertOk()
        ->assertJsonPath('inventory_adjustment_count', 1)
        ->assertJsonPath('inventory_adjustments.0.product_name', 'Lemon Cola')
        ->assertJsonPath('inventory_adjustments.0.quantity', 2)
        ->assertJsonPath('inventory_adjustments.0.reason_label', 'Wastage')
        ->assertJsonPath('inventory_adjustments.0.note', 'Dropped tray')
        ->assertJsonPath('expense_totals', ['cash' => '0.00', 'cashless' => '0.00', 'total' => '0.00'])
        ->assertJsonPath('expense_count', 0);
});
