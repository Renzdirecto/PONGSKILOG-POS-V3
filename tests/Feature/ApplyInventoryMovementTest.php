<?php

use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('tracked stock initializes once and appends movements with one version increment per change', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true, 'low_stock_threshold' => 4]);
    $actor = User::factory()->create();
    $action = app(ApplyInventoryMovement::class);
    $this->travelTo(now()->startOfSecond());

    $addition = $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 10, 'Opening count', $actor);
    $originalMovement = $addition->refresh()->getAttributes();
    $balance = BranchInventory::query()->sole();

    expect($balance->on_hand)->toBe(10);
    expect($balance->version)->toBe(1);
    expect($addition->quantity_delta)->toBe(10);
    expect($addition->reason)->toBe('Opening count');
    expect($addition->createdBy->is($actor))->toBeTrue();
    expect($addition->created_at->equalTo(now()))->toBeTrue();

    $this->travel(1)->minute();
    $deduction = $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, -3);

    expect($balance->refresh()->on_hand)->toBe(7);
    expect($balance->version)->toBe(2);
    expect($deduction->quantity_delta)->toBe(-3);
    expect($deduction->branch_id)->toBe($configuration->branch_id);
    expect($deduction->product_id)->toBe($configuration->product_id);
    expect($deduction->created_by_user_id)->toBeNull();
    expect($addition->refresh()->getAttributes())->toBe($originalMovement);
    expect($configuration->refresh()->low_stock_threshold)->toBe(4);
    $this->assertDatabaseCount('branch_inventory', 1);
    $this->assertDatabaseCount('inventory_movements', 2);
});

test('insufficient stock preserves the complete balance and ledger', function (int $quantityDelta) {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $action = app(ApplyInventoryMovement::class);
    $movement = $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 2);
    $balance = BranchInventory::query()->sole();
    $attributes = $balance->refresh()->getAttributes();

    expect(fn () => $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, $quantityDelta))
        ->toThrow(ValidationException::class, 'Insufficient stock');

    expect($balance->refresh()->getAttributes())->toBe($attributes);
    expect(InventoryMovement::query()->sole()->is($movement))->toBeTrue();
})->with(['overdraw' => -3, 'minimum integer' => PHP_INT_MIN]);

test('a deduction from missing stock rolls back the newly initialized row', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);

    expect(fn () => app(ApplyInventoryMovement::class)->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, -1))
        ->toThrow(ValidationException::class, 'Insufficient stock');

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('deducting the last unit succeeds and a subsequent deduction fails', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $action = app(ApplyInventoryMovement::class);
    $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 1);
    $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, -1);

    expect(fn () => $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, -1))
        ->toThrow(ValidationException::class, 'Insufficient stock');

    expect(BranchInventory::query()->sole()->on_hand)->toBe(0);
    expect(BranchInventory::query()->sole()->version)->toBe(2);
    $this->assertDatabaseCount('inventory_movements', 2);
});

test('stock changes isolate both the branch and the product', function () {
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    BranchProduct::factory()->for($main)->for($product)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($qave)->for($product)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($main)->for($otherProduct)->create(['tracks_inventory' => true]);
    $action = app(ApplyInventoryMovement::class);
    $action->execute($main, $product, InventoryMovementType::ManualAdjustment, 10);
    $action->execute($qave, $product, InventoryMovementType::ManualAdjustment, 3);
    $action->execute($main, $otherProduct, InventoryMovementType::ManualAdjustment, 6);

    $action->execute($main, $product, InventoryMovementType::ManualAdjustment, -2);

    $this->assertDatabaseHas('branch_inventory', ['branch_id' => $main->id, 'product_id' => $product->id, 'on_hand' => 8, 'version' => 2]);
    $this->assertDatabaseHas('branch_inventory', ['branch_id' => $qave->id, 'product_id' => $product->id, 'on_hand' => 3, 'version' => 1]);
    $this->assertDatabaseHas('branch_inventory', ['branch_id' => $main->id, 'product_id' => $otherProduct->id, 'on_hand' => 6, 'version' => 1]);
    expect(InventoryMovement::query()->where('branch_id', $qave->id)->sole()->quantity_delta)->toBe(3);
    $this->assertDatabaseCount('inventory_movements', 4);
});

test('persisted disabled tracking overrides loaded and forged configuration', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $branch = $configuration->branch->load('branchProducts');
    $product = $configuration->product->load('branchProducts');
    BranchProduct::query()->whereKey($configuration->id)->update(['tracks_inventory' => false]);

    expect(fn () => app(ApplyInventoryMovement::class)->execute($branch, $product, InventoryMovementType::ManualAdjustment, 1))
        ->toThrow(ValidationException::class, 'Inventory is not tracked');

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('tracking at a different branch does not permit a missing branch configuration', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $otherBranch = Branch::factory()->create();

    expect(fn () => app(ApplyInventoryMovement::class)->execute($otherBranch, $configuration->product, InventoryMovementType::ManualAdjustment, 1))
        ->toThrow(ValidationException::class, 'Inventory is not tracked');

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('branch_products', 1);
});

test('zero deltas leave existing stock and history unchanged', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($configuration->branch)->for($configuration->product)->create(['on_hand' => 7, 'version' => 3]);
    $attributes = $balance->refresh()->getAttributes();

    expect(fn () => app(ApplyInventoryMovement::class)->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 0))
        ->toThrow(ValidationException::class, 'must not be zero');

    expect($balance->refresh()->getAttributes())->toBe($attributes);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('inventory rejects missing persisted inputs before creating stock', function (string $missing) {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $actor = User::factory()->create();
    $record = match ($missing) {
        'branch' => $branch,
        'product' => $product,
        'actor' => $actor,
    };
    $record->delete();

    expect(fn () => app(ApplyInventoryMovement::class)->execute($branch, $product, InventoryMovementType::ManualAdjustment, 1, actor: $actor))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['branch', 'product', 'actor']);

test('ledger insertion failure rolls back stock initialization or an existing balance update', function (bool $existing) {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $action = app(ApplyInventoryMovement::class);
    if ($existing) {
        $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 5);
    }
    $balances = BranchInventory::query()->get()->toArray();
    $movements = InventoryMovement::query()->get()->toArray();
    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_starts_with($sql, 'insert into "inventory_movements"')) {
            throw new RuntimeException('Forced ledger insert failure');
        }
    });

    expect(fn () => $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 2))
        ->toThrow(RuntimeException::class, 'Forced ledger insert failure');

    expect(BranchInventory::query()->get()->toArray())->toBe($balances);
    expect(InventoryMovement::query()->get()->toArray())->toBe($movements);
})->with(['first row' => false, 'existing balance' => true]);

test('balance update failure leaves no ledger or balance changes', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_starts_with($sql, 'update "branch_inventory"')) {
            throw new RuntimeException('Forced balance update failure');
        }
    });

    expect(fn () => app(ApplyInventoryMovement::class)->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 2))
        ->toThrow(RuntimeException::class, 'Forced balance update failure');

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('outer workflow rollback also rolls back a successful inventory operation', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);

    expect(fn () => DB::transaction(function () use ($configuration): void {
        app(ApplyInventoryMovement::class)->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 2);
        throw new RuntimeException('Outer workflow failed');
    }))->toThrow(RuntimeException::class, 'Outer workflow failed');

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('overflow is rejected before integer arithmetic can lose precision', function (string $column) {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($configuration->branch)->for($configuration->product)->create([$column => PHP_INT_MAX]);
    $attributes = $balance->refresh()->getAttributes();

    expect(fn () => app(ApplyInventoryMovement::class)->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 1))
        ->toThrow(ValidationException::class, 'supported integer range');

    expect($balance->refresh()->getAttributes())->toBe($attributes);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['on_hand', 'version']);

test('large whole unit quantities round trip without float conversion', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);

    $movement = app(ApplyInventoryMovement::class)->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, PHP_INT_MAX);

    expect($movement->refresh()->quantity_delta)->toBe(PHP_INT_MAX);
    expect(BranchInventory::query()->sole()->on_hand)->toBe(PHP_INT_MAX);
});

test('future references are retained without requiring unimplemented tables', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $orderId = (string) Str::uuid();
    $expenseId = (string) Str::uuid();
    $transferId = (string) Str::uuid();

    $movement = app(ApplyInventoryMovement::class)->execute(
        $configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 1,
        orderId: $orderId, storeSessionExpenseId: $expenseId, stockTransferId: $transferId,
    )->refresh();

    expect($movement->order_id)->toBe($orderId);
    expect($movement->store_session_expense_id)->toBe($expenseId);
    expect($movement->stock_transfer_id)->toBe($transferId);
});

test('malformed future reference IDs cannot mutate inventory', function (string $parameter) {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);

    expect(fn () => app(ApplyInventoryMovement::class)->execute(
        $configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 1,
        ...[$parameter => 'invalid'],
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['orderId', 'storeSessionExpenseId', 'stockTransferId']);

test('balance reads and writes stay inside the inventory transaction', function () {
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $startingLevel = DB::transactionLevel();
    $observed = [];
    DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use (&$observed): void {
        if (str_contains($sql, '"branch_inventory"') || str_contains($sql, '"inventory_movements"')) {
            $observed[] = $connection->transactionLevel();
        }
    });

    app(ApplyInventoryMovement::class)->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 1);

    expect($observed)->not->toBeEmpty();
    foreach ($observed as $level) {
        expect($level)->toBeGreaterThan($startingLevel);
    }
    expect(DB::transactionLevel())->toBe($startingLevel);
});
