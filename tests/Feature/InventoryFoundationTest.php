<?php

use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('inventory balances have UUIDs zero defaults and branch product relationships', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $balance = BranchInventory::create(['branch_id' => $branch->id, 'product_id' => $product->id])->refresh();
    $otherBranchBalance = BranchInventory::factory()->for($product)->create();
    BranchInventory::factory()->create();

    expect($balance->id)->toBeUuid();
    expect($balance->on_hand)->toBe(0);
    expect($balance->version)->toBe(0);
    expect($balance->branch->is($branch))->toBeTrue();
    expect($balance->product->is($product))->toBeTrue();
    expect($branch->inventoryBalances()->sole()->is($balance))->toBeTrue();
    expect($product->inventoryBalances()->pluck('id')->all())->toEqualCanonicalizing([$balance->id, $otherBranchBalance->id]);
    expect(Schema::hasColumn('branch_inventory', 'low_stock_threshold'))->toBeFalse();
});

test('the database enforces one balance per branch and product', function () {
    $balance = BranchInventory::factory()->create();
    $duplicate = $balance->getAttributes();
    $duplicate['id'] = (string) Str::uuid();

    expect(fn () => DB::table('branch_inventory')->insert($duplicate))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the database rejects negative balance state', function (string $column) {
    $balance = BranchInventory::factory()->create();

    expect(fn () => DB::table('branch_inventory')->where('id', $balance->id)->update([$column => -1]))
        ->toThrow(QueryException::class);
})->with(['on_hand', 'version']);

test('movements preserve signed integer quantities and all frozen enum values', function (InventoryMovementType $type) {
    $movement = InventoryMovement::factory()->create(['movement_type' => $type, 'quantity_delta' => -3])->refresh();

    expect($movement->id)->toBeUuid();
    expect($movement->movement_type)->toBe($type);
    expect($movement->quantity_delta)->toBe(-3);
    expect($movement->created_at)->not->toBeNull();
    expect($movement->created_by_user_id)->toBeNull();
    expect($movement->order_id)->toBeNull();
    expect($movement->store_session_expense_id)->toBeNull();
    expect($movement->stock_transfer_id)->toBeNull();
    expect(Schema::hasColumn('inventory_movements', 'updated_at'))->toBeFalse();
})->with(InventoryMovementType::cases());

test('movements resolve their branch product and actor', function () {
    $actor = User::factory()->create();
    $movement = InventoryMovement::factory()->for($actor, 'createdBy')->create();

    expect($movement->branch->id)->toBe($movement->branch_id);
    expect($movement->product->id)->toBe($movement->product_id);
    expect($movement->createdBy->is($actor))->toBeTrue();
});

test('the database rejects invalid ledger data', function (array $attributes) {
    $movement = InventoryMovement::factory()->create();

    expect(fn () => DB::table('inventory_movements')->where('id', $movement->id)->update($attributes))
        ->toThrow(QueryException::class);
})->with([
    'zero delta' => [['quantity_delta' => 0]],
    'unknown type' => [['movement_type' => 'opening']],
    'missing branch' => [['branch_id' => '00000000-0000-4000-8000-000000000001']],
    'missing product' => [['product_id' => '00000000-0000-4000-8000-000000000001']],
    'missing actor' => [['created_by_user_id' => 999999]],
]);

test('inventory balances prevent deletion of referenced records', function (string $relationship) {
    $balance = BranchInventory::factory()->create();

    expect(fn () => $balance->{$relationship}->delete())->toThrow(QueryException::class);
})->with(['branch', 'product']);

test('inventory history prevents deletion of its branch product or actor', function (string $relationship) {
    $movement = InventoryMovement::factory()->for(User::factory(), 'createdBy')->create();

    expect(fn () => $movement->{$relationship}->delete())->toThrow(QueryException::class);
})->with(['branch', 'product', 'createdBy']);
