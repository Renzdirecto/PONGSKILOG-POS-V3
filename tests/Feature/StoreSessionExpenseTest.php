<?php

use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Enums\InventoryMovementType;
use App\Events\StoreExpenseRecorded;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function storeExpenseUser(Branch $branch, string $role = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

/** @return array<string, mixed> */
function storeExpensePayload(array $overrides = []): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'description' => 'Ice',
        'amount' => '100.00',
        'payment_source' => 'cash',
        'note' => 'Afternoon operations',
        'restock' => false,
        ...$overrides,
    ];
}

test('cashier records a normal expense without inventory or payment effects', function () {
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    $session = StoreSession::factory()->for($branch)->create();
    $matchingProduct = Product::factory()->create(['name' => 'Ice']);
    BranchProduct::factory()->for($branch)->for($matchingProduct)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($branch)->for($matchingProduct)->create(['on_hand' => 7]);
    Event::fake([StoreExpenseRecorded::class]);
    $payload = storeExpensePayload();

    $response = $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), $payload);

    $response->assertOk()->assertJsonPath('expense.description', 'Ice')->assertJsonPath('expense.amount', '100.00');
    $expense = StoreSessionExpense::query()->sole();
    expect($expense->branch_id)->toBe($branch->id)
        ->and($expense->store_session_id)->toBe($session->id)
        ->and($expense->created_by_user_id)->toBe($cashier->id)
        ->and($expense->payment_source)->toBe('cash');
    $this->assertDatabaseCount('store_session_expense_items', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    expect(BranchInventory::query()->sole()->on_hand)->toBe(7);
    $this->assertDatabaseCount('payments', 0);
    expect(AuditLog::query()->where('action', 'store_expense_recorded')->count())->toBe(1);
    Event::assertDispatchedTimes(StoreExpenseRecorded::class, 1);

    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), $payload)->assertOk();
    $this->assertDatabaseCount('store_session_expenses', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    Event::assertDispatchedTimes(StoreExpenseRecorded::class, 1);
    expect(fn () => $expense->update(['description' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $expense->delete())->toThrow(LogicException::class);
});

test('cashless expense is totaled separately and prior session and other branch records are excluded', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    $prior = StoreSession::factory()->closed()->for($branch)->create();
    $session = StoreSession::factory()->for($branch)->create();
    $otherSession = StoreSession::factory()->for($other)->create();
    StoreSessionExpense::factory()->for($branch)->for($prior, 'storeSession')->create(['amount' => '999.00']);
    StoreSessionExpense::factory()->for($other)->for($otherSession, 'storeSession')->create(['amount' => '888.00']);
    StoreSessionExpense::factory()->for($branch)->for($session, 'storeSession')->create(['amount' => '200.00', 'payment_source' => 'cash']);
    StoreSessionExpense::factory()->for($branch)->for($session, 'storeSession')->create(['amount' => '100.00', 'payment_source' => 'cash']);
    StoreSessionExpense::factory()->for($branch)->for($session, 'storeSession')->create(['amount' => '500.00', 'payment_source' => 'cashless']);
    StoreSessionExpense::factory()->for($branch)->for($session, 'storeSession')->create(['amount' => '50.00', 'payment_source' => 'cashless']);

    $this->actingAs($cashier)->getJson(route('store-sessions.current'))
        ->assertOk()
        ->assertJsonPath('expense_totals.cash', '300.00')
        ->assertJsonPath('expense_totals.cashless', '550.00')
        ->assertJsonPath('expense_totals.total', '850.00')
        ->assertJsonCount(4, 'expenses');
});

test('explicit restock atomically records one item and increases tracked branch stock once', function () {
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch, 'cashier_kitchen');
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['name' => 'Crystal']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 4]);
    $payload = storeExpensePayload([
        'description' => 'Softdrink restock', 'amount' => '600.00', 'restock' => true,
        'product_id' => $product->id, 'quantity' => 20,
    ]);

    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), $payload)
        ->assertOk()->assertJsonPath('expense.item.product_name', 'Crystal')->assertJsonPath('expense.item.quantity', 20);

    expect(BranchInventory::query()->sole()->on_hand)->toBe(24);
    $movement = InventoryMovement::query()->sole();
    expect($movement->movement_type)->toBe(InventoryMovementType::StorePurchaseRestock)
        ->and($movement->quantity_delta)->toBe(20)
        ->and($movement->store_session_expense_id)->toBe(StoreSessionExpense::query()->sole()->id);
    $this->assertDatabaseCount('store_session_expense_items', 1);

    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), $payload)->assertOk();
    expect(BranchInventory::query()->sole()->on_hand)->toBe(24);
    $this->assertDatabaseCount('inventory_movements', 1);
    $item = StoreSessionExpense::query()->sole()->item()->sole();
    expect(fn () => $item->update(['quantity' => 21]))->toThrow(LogicException::class)
        ->and(fn () => $item->delete())->toThrow(LogicException::class);
});

test('changed intent conflicts and leaves the original expense unchanged', function () {
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    StoreSession::factory()->for($branch)->create();
    $payload = storeExpensePayload();
    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), $payload)->assertOk();

    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), [...$payload, 'amount' => '101.00'])
        ->assertConflict();

    expect(StoreSessionExpense::query()->sole()->amount)->toBe('100.00');
    $this->assertDatabaseCount('audit_logs', 1);
});

test('authorization and current open session are enforced', function (string $case) {
    $branch = Branch::factory()->create();
    $role = $case === 'kitchen' ? 'kitchen_staff' : 'cashier';
    $user = storeExpenseUser($branch, $role);
    if ($case !== 'closed') {
        StoreSession::factory()->for($branch)->create();
    }

    $response = match ($case) {
        'guest' => $this->postJson(route('store-session-expenses.store'), storeExpensePayload()),
        'kitchen' => $this->actingAs($user)->postJson(route('store-session-expenses.store'), storeExpensePayload()),
        'closed' => $this->actingAs($user)->postJson(route('store-session-expenses.store'), storeExpensePayload()),
    };

    $case === 'guest' ? $response->assertUnauthorized() : ($case === 'kitchen' ? $response->assertForbidden() : $response->assertUnprocessable());
    $this->assertDatabaseCount('store_session_expenses', 0);
})->with(['guest', 'kitchen', 'closed']);

test('owner and unassigned operational users cannot create expenses', function (string $case) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $case === 'owner' ? 'owner' : 'cashier')->sole());
    if ($case === 'owner') {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    $response = $this->actingAs($user)->postJson(route('store-session-expenses.store'), storeExpensePayload());
    $case === 'owner'
        ? $response->assertForbidden()
        : $response->assertRedirectToRoute('workspace');
    $this->assertDatabaseCount('store_session_expenses', 0);
})->with(['owner', 'unassigned cashier']);

test('client session identifiers are ignored in favor of the current open session', function () {
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    $prior = StoreSession::factory()->closed()->for($branch)->create();
    $current = StoreSession::factory()->for($branch)->create();

    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), storeExpensePayload([
        'store_session_id' => $prior->id,
    ]))->assertOk();

    expect(StoreSessionExpense::query()->sole()->store_session_id)->toBe($current->id);
});

test('invalid money and an untracked product are rejected without effects', function () {
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => false]);

    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), storeExpensePayload(['amount' => '0.00']))
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->actingAs($cashier)->postJson(route('store-session-expenses.store'), storeExpensePayload([
        'restock' => true, 'product_id' => $product->id, 'quantity' => 2,
    ]))->assertUnprocessable()->assertJsonValidationErrors('product_id');
    $this->assertDatabaseCount('store_session_expenses', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('private receipt is accepted and another branch receives not found', function () {
    Storage::fake('receipts');
    config()->set('filesystems.store_expense_receipts_disk', 'receipts');
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    $otherCashier = storeExpenseUser($other);
    StoreSession::factory()->for($branch)->create();
    StoreSession::factory()->for($other)->create();

    $response = $this->actingAs($cashier)->post(route('store-session-expenses.store'), storeExpensePayload([
        'receipt' => UploadedFile::fake()->image('receipt.jpg', 320, 320),
    ]), ['Accept' => 'application/json']);
    $response->assertOk();
    $expense = StoreSessionExpense::query()->sole();
    Storage::disk('receipts')->assertExists($expense->receipt_image_path);

    $this->actingAs($cashier)->get(route('store-session-expenses.receipt', $expense->id))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($otherCashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $other->id])
        ->get(route('store-session-expenses.receipt', $expense->id))
        ->assertNotFound();
});

test('receipt validation rejects unsupported and oversized files without persistence', function (UploadedFile $receipt) {
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    StoreSession::factory()->for($branch)->create();

    $this->actingAs($cashier)->post(route('store-session-expenses.store'), storeExpensePayload([
        'receipt' => $receipt,
    ]), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt');

    $this->assertDatabaseCount('store_session_expenses', 0);
})->with([
    'svg' => fn () => UploadedFile::fake()->create('receipt.svg', 20, 'image/svg+xml'),
    'oversized jpg' => fn () => UploadedFile::fake()->image('receipt.jpg', 320, 320)->size(2049),
]);

test('expense realtime payload is compact and branch channel authorization is scoped', function () {
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    $kitchen = storeExpenseUser($branch, 'kitchen_staff');
    $session = StoreSession::factory()->for($branch)->create();
    $expense = StoreSessionExpense::factory()->for($branch)->for($session, 'storeSession')->create();
    $event = new StoreExpenseRecorded($expense, false);
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app', 'options' => ['cluster' => 'ap1'],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();

    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->broadcastAs())->toBe('store.expense_recorded')
        ->and($event->broadcastOn()[0]->name)->toBe('private-branch.'.$branch->id.'.store-session')
        ->and($event->broadcastWith())->toMatchArray([
            'event_type' => 'store.expense_recorded',
            'branch_id' => $branch->id,
            'expense_id' => $expense->id,
            'store_session_id' => $session->id,
            'inventory_linked' => false,
        ])
        ->and($event->broadcastWith())->not->toHaveKeys(['description', 'note', 'receipt_image_path', 'created_by_user_id']);

    $channel = 'private-branch.'.$branch->id.'.store-session';
    $this->actingAs($cashier)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456', 'channel_name' => $channel,
    ])->assertOk();
    $this->actingAs($kitchen)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456', 'channel_name' => $channel,
    ])->assertForbidden();
    $this->actingAs($cashier)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456', 'channel_name' => 'private-branch.'.$otherBranch->id.'.store-session',
    ])->assertForbidden();
});

test('audit failure rolls back the expense stock and newly stored receipt', function () {
    Storage::fake('receipts');
    config()->set('filesystems.store_expense_receipts_disk', 'receipts');
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_starts_with($sql, 'insert into "audit_logs"')) {
            throw new RuntimeException('Forced audit failure');
        }
    });

    expect(fn () => app(RecordStoreSessionExpense::class)->execute($cashier, $branch, storeExpensePayload([
        'restock' => true, 'product_id' => $product->id, 'quantity' => 3,
        'receipt' => UploadedFile::fake()->image('receipt.jpg', 320, 320),
    ])))->toThrow(RuntimeException::class, 'Forced audit failure');

    $this->assertDatabaseCount('store_session_expenses', 0);
    $this->assertDatabaseCount('store_session_expense_items', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('branch_inventory', 0);
    Storage::disk('receipts')->assertDirectoryEmpty('store-expense-receipts');
});

test('failure at each restock persistence boundary rolls back every effect', function (string $failingTable) {
    $branch = Branch::factory()->create();
    $cashier = storeExpenseUser($branch);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    Event::fake([StoreExpenseRecorded::class]);
    DB::connection()->beforeExecuting(function (string $sql) use ($failingTable): void {
        if (str_starts_with($sql, 'insert into "'.$failingTable.'"') || str_starts_with($sql, 'update "'.$failingTable.'"')) {
            throw new RuntimeException('Forced '.$failingTable.' failure');
        }
    });

    expect(fn () => app(RecordStoreSessionExpense::class)->execute($cashier, $branch, storeExpensePayload([
        'restock' => true, 'product_id' => $product->id, 'quantity' => 3,
    ])))->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('store_session_expenses', 0);
    $this->assertDatabaseCount('store_session_expense_items', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    Event::assertNotDispatched(StoreExpenseRecorded::class);
})->with(['store_session_expense_items', 'branch_inventory', 'inventory_movements']);
