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
function stockCorrection(StoreCloseScenario $scenario, array $overrides = [], ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? $scenario->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->postJson(route('store-session-inventory-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'direction' => 'decrease',
            'reason_code' => 'wastage',
            'product_id' => $scenario->product->id,
            'quantity' => 1,
            'note' => null,
            ...$overrides,
        ]);
}

test('a decrease correction deducts stock only, audits once and reuses inventory realtime', function () {
    $scenario = $this->scenario;
    $scenario->done($scenario->payNow(1, 'cash'));
    $scenario->expense('20.00', 'cash');
    $before = $scenario->reconciliation();
    Event::fake([InventoryChanged::class, CustomerCatalogChanged::class]);
    $payload = ['idempotency_key' => (string) Str::uuid(), 'note' => 'Dropped tray'];

    stockCorrection($scenario, $payload)->assertOk()
        ->assertJsonPath('adjustment.reason_label', 'Wastage')
        ->assertJsonPath('adjustment.direction', 'decrease')
        ->assertJsonPath('adjustment.on_hand', 48)
        ->assertJsonPath('adjustment.quantity', 1);
    stockCorrection($scenario, $payload)->assertOk();

    $adjustment = StoreSessionInventoryAdjustment::query()->sole();
    $movement = InventoryMovement::query()->whereKey($adjustment->inventory_movement_id)->sole();
    expect($scenario->stock->fresh()->on_hand)->toBe(48)
        ->and($adjustment->store_session_id)->toBe($scenario->session->id)
        ->and($adjustment->created_by_user_id)->toBe($scenario->cashier->id)
        ->and($movement->movement_type->value)->toBe('manual_adjustment')
        ->and($movement->quantity_delta)->toBe(-1)
        ->and($movement->reason)->toBe('Stock correction: Wastage — Dropped tray')
        ->and($scenario->session->expenses()->count())->toBe(1)
        ->and($scenario->reconciliation())->toBe($before);
    $this->assertDatabaseCount('payments', 1);
    $audit = AuditLog::query()->where('action', 'store_session.inventory_adjusted')->sole();
    expect($audit->module)->toBe('inventory')
        ->and($audit->before)->toBe(['on_hand' => 49])
        ->and($audit->after)->toBe(['on_hand' => 48])
        ->and($audit->metadata)->toMatchArray([
            'store_session_id' => $scenario->session->id, 'product_name' => 'Lemon Cola',
            'direction' => 'decrease', 'quantity' => 1, 'quantity_delta' => -1, 'reason_code' => 'wastage',
        ]);
    Event::assertDispatchedTimes(InventoryChanged::class, 1);
    Event::assertDispatchedTimes(CustomerCatalogChanged::class, 1);

    stockCorrection($scenario, [...$payload, 'quantity' => 2])->assertConflict();
    stockCorrection($scenario, [...$payload, 'direction' => 'increase', 'reason_code' => 'physical_count'])->assertConflict();
});

test('an increase correction adds stock without becoming a purchase, expense or Cash/Cashless change', function () {
    $scenario = $this->scenario;
    $scenario->expense('20.00', 'cash');
    $before = $scenario->reconciliation();

    stockCorrection($scenario, ['direction' => 'increase', 'reason_code' => 'found_stock', 'quantity' => 4, 'note' => 'Box behind the counter'])
        ->assertOk()
        ->assertJsonPath('adjustment.direction', 'increase')
        ->assertJsonPath('adjustment.reason_label', 'Found stock')
        ->assertJsonPath('adjustment.on_hand', 54);

    $adjustment = StoreSessionInventoryAdjustment::query()->sole();
    $movement = InventoryMovement::query()->whereKey($adjustment->inventory_movement_id)->sole();
    expect($adjustment->quantity)->toBe(4)
        ->and($movement->movement_type->value)->toBe('manual_adjustment')
        ->and($movement->quantity_delta)->toBe(4)
        ->and($movement->store_session_expense_id)->toBeNull()
        ->and($scenario->session->expenses()->count())->toBe(1)
        ->and($scenario->reconciliation())->toBe($before);
    expect(AuditLog::query()->where('action', 'store_session.inventory_adjusted')->sole())
        ->before->toBe(['on_hand' => 50])
        ->after->toBe(['on_hand' => 54]);
});

test('a physical count may correct stock in either direction', function (string $direction, int $expected) {
    stockCorrection($this->scenario, ['direction' => $direction, 'reason_code' => 'physical_count', 'quantity' => 5])->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe($expected);
})->with([
    'shelf has less' => ['decrease', 45],
    'shelf has more' => ['increase', 55],
]);

test('an increase can restore stock of a product that reached zero', function () {
    $this->scenario->stock->update(['on_hand' => 0]);

    stockCorrection($this->scenario, ['direction' => 'increase', 'reason_code' => 'physical_count', 'quantity' => 3])->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(3);
});

test('each loss reason removes stock', function (string $reason) {
    stockCorrection($this->scenario, ['reason_code' => $reason, 'quantity' => 3])->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(47)
        ->and(StoreSessionInventoryAdjustment::query()->sole()->reason_code->value)->toBe($reason);
})->with(['missing_stock', 'wastage', 'damaged']);

test('a reason must explain the direction of the correction', function (string $direction, string $reason) {
    stockCorrection($this->scenario, ['direction' => $direction, 'reason_code' => $reason])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason_code']);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    'wastage cannot add' => ['increase', 'wastage'],
    'damaged cannot add' => ['increase', 'damaged'],
    'missing stock cannot add' => ['increase', 'missing_stock'],
    'found stock cannot remove' => ['decrease', 'found_stock'],
]);

test('free items are Giveaways, never Stock Corrections', function (string $reason) {
    stockCorrection($this->scenario, ['reason_code' => $reason])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason_code' => 'Choose a Stock Correction reason. Record free items as a Giveaway.']);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
    $this->assertDatabaseCount('store_session_inventory_adjustments', 0);
})->with(['complimentary', 'staff_meal']);

test('the direction is required and must be known', function (mixed $direction) {
    stockCorrection($this->scenario, ['direction' => $direction])->assertUnprocessable()->assertJsonValidationErrors(['direction']);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
})->with([null, 'sideways']);

test('Other requires an explanation', function () {
    stockCorrection($this->scenario, ['reason_code' => 'other', 'note' => '   '])
        ->assertUnprocessable()->assertJsonValidationErrors(['note' => 'Explain the correction when the reason is Other.']);
    stockCorrection($this->scenario, ['reason_code' => 'other', 'note' => 'Spilled during delivery'])->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(49);
});

test('invalid quantities and insufficient stock never change inventory', function (mixed $quantity, string $field) {
    stockCorrection($this->scenario, ['quantity' => $quantity])->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
    $this->assertDatabaseCount('store_session_inventory_adjustments', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    'zero' => [0, 'quantity'],
    'negative' => [-1, 'quantity'],
    'fraction' => [1.5, 'quantity'],
    'more than stock' => [51, 'quantity'],
    'over the maximum' => [1000001, 'quantity'],
]);

test('products that are not tracked in the active branch are rejected', function () {
    $other = Branch::factory()->create();
    $foreign = Product::factory()->create();
    BranchProduct::factory()->for($other)->for($foreign)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($other)->for($foreign)->create(['on_hand' => 10]);

    stockCorrection($this->scenario, ['product_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
    stockCorrection($this->scenario, ['product_id' => $foreign->id, 'direction' => 'increase', 'reason_code' => 'found_stock'])
        ->assertUnprocessable()->assertJsonValidationErrors(['product_id']);

    expect(BranchInventory::query()->where('product_id', $foreign->id)->value('on_hand'))->toBe(10);
});

test('a closed Store Session rejects corrections', function () {
    $this->scenario->session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->scenario->cashier->id]);

    stockCorrection($this->scenario)->assertUnprocessable()->assertJsonValidationErrors(['store']);
    stockCorrection($this->scenario, ['direction' => 'increase', 'reason_code' => 'found_stock'])->assertUnprocessable()->assertJsonValidationErrors(['store']);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
});

test('roles without Store Session operations are rejected', function (string $role) {
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($this->scenario->branch, ['is_active' => true]);

    stockCorrection($this->scenario, [], $user)->assertForbidden();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50);
})->with(['kitchen_staff', 'owner']);

test('a full access super admin corrects the selected branch stock as the audited actor without an assignment', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());

    stockCorrection($this->scenario, [], $superAdmin)->assertOk()->assertJsonPath('adjustment.on_hand', 49);

    expect(StoreSessionInventoryAdjustment::query()->sole()->created_by_user_id)->toBe($superAdmin->id)
        ->and(AuditLog::query()->where('action', 'store_session.inventory_adjusted')->sole()->user_id)->toBe($superAdmin->id)
        ->and($superAdmin->branches()->count())->toBe(0);
    stockCorrection($this->scenario, ['quantity' => 50], $superAdmin)->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    expect($this->scenario->stock->fresh()->on_hand)->toBe(49);
});

test('a cashier with kitchen access may correct stock', function () {
    stockCorrection($this->scenario, [], $this->scenario->user('cashier_kitchen'))->assertOk();

    expect($this->scenario->stock->fresh()->on_hand)->toBe(49);
});

test('guests, inactive users and unassigned cashiers cannot correct the active branch stock', function (string $case, int $status) {
    $scenario = $this->scenario;
    $request = match ($case) {
        'guest' => fn () => $this->postJson(route('store-session-inventory-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(), 'direction' => 'decrease', 'reason_code' => 'wastage', 'product_id' => $scenario->product->id, 'quantity' => 1,
        ]),
        'inactive user' => fn () => stockCorrection($scenario, [], $scenario->user('cashier', active: false)),
        'inactive assignment' => fn () => stockCorrection($scenario, [], $scenario->user('cashier', assignmentActive: false)),
        'other branch cashier' => fn () => stockCorrection($scenario, [], $scenario->user('cashier', Branch::factory()->create())),
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

test('a failed correction write rolls back stock, movement and attribution without realtime', function (string $failure) {
    Event::fake([InventoryChanged::class, CustomerCatalogChanged::class]);
    match ($failure) {
        'correction record' => StoreSessionInventoryAdjustment::creating(fn () => throw new RuntimeException('Injected correction failure')),
        'audit' => app()->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(...$arguments): AuditLog
            {
                throw new RuntimeException('Injected audit failure');
            }
        }),
    };

    expect(fn () => app(RecordStoreSessionInventoryAdjustment::class)->execute($this->scenario->cashier, $this->scenario->branch, [
        'idempotency_key' => (string) Str::uuid(), 'direction' => 'increase', 'reason_code' => 'found_stock', 'product_id' => $this->scenario->product->id, 'quantity' => 2,
    ]))->toThrow(RuntimeException::class);

    expect($this->scenario->stock->fresh()->on_hand)->toBe(50)
        ->and($this->scenario->stock->fresh()->version)->toBe($this->scenario->stock->version);
    $this->assertDatabaseCount('store_session_inventory_adjustments', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    expect(AuditLog::query()->where('action', 'store_session.inventory_adjusted')->count())->toBe(0);
    Event::assertNotDispatched(InventoryChanged::class);
    Event::assertNotDispatched(CustomerCatalogChanged::class);
})->with(['correction record', 'audit']);

test('corrections appear in the current-session projection without changing money totals', function () {
    stockCorrection($this->scenario, ['reason_code' => 'wastage', 'quantity' => 2, 'note' => 'Dropped tray'])->assertOk();
    $this->travel(1)->seconds();
    stockCorrection($this->scenario, ['direction' => 'increase', 'reason_code' => 'found_stock', 'quantity' => 1])->assertOk();

    $this->actingAs($this->scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->scenario->branch->id])
        ->getJson(route('store-sessions.current'))->assertOk()
        ->assertJsonPath('inventory_adjustment_count', 2)
        ->assertJsonPath('inventory_adjustments.0.direction', 'increase')
        ->assertJsonPath('inventory_adjustments.0.reason_label', 'Found stock')
        ->assertJsonPath('inventory_adjustments.1.product_name', 'Lemon Cola')
        ->assertJsonPath('inventory_adjustments.1.direction', 'decrease')
        ->assertJsonPath('inventory_adjustments.1.quantity', 2)
        ->assertJsonPath('inventory_adjustments.1.reason_label', 'Wastage')
        ->assertJsonPath('inventory_adjustments.1.note', 'Dropped tray')
        ->assertJsonPath('expense_totals', ['cash' => '0.00', 'cashless' => '0.00', 'total' => '0.00'])
        ->assertJsonPath('expense_count', 0);
});

test('historical complimentary and staff meal corrections keep their labels', function () {
    $movement = InventoryMovement::query()->create([
        'branch_id' => $this->scenario->branch->id, 'product_id' => $this->scenario->product->id,
        'movement_type' => 'manual_adjustment', 'quantity_delta' => -1, 'reason' => 'Store Session adjustment: Staff meal',
    ]);
    StoreSessionInventoryAdjustment::query()->create([
        'branch_id' => $this->scenario->branch->id, 'store_session_id' => $this->scenario->session->id,
        'product_id' => $this->scenario->product->id, 'inventory_movement_id' => $movement->id,
        'reason_code' => 'staff_meal', 'quantity' => 1, 'created_by_user_id' => $this->scenario->cashier->id,
        'idempotency_key' => (string) Str::uuid(), 'intent_hash' => str_repeat('a', 64),
    ]);

    $this->actingAs($this->scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->scenario->branch->id])
        ->getJson(route('store-sessions.current'))->assertOk()
        ->assertJsonPath('inventory_adjustments.0.reason_label', 'Staff meal')
        ->assertJsonPath('inventory_adjustments.0.direction', 'decrease');
});
