<?php

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function inventoryCashier(Branch $branch): User
{
    test()->seed(RbacSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

test('cashier stock state controls availability without exposing inventory internals', function (
    bool $tracked,
    ?int $onHand,
    ?int $threshold,
    bool $catalogAvailable,
    bool $expectedAvailable,
    string $status,
) {
    $branch = Branch::factory()->create();
    $user = inventoryCashier($branch);
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create([
        'tracks_inventory' => $tracked, 'low_stock_threshold' => $threshold, 'is_available' => $catalogAvailable,
    ]);
    if ($onHand !== null) {
        BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $onHand]);
    }

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->has('catalog.products', 1)
        ->where('catalog.products.0.id', $product->id)
        ->where('catalog.products.0.is_available', $expectedAvailable)
        ->where('catalog.products.0.stock_status', $status)
        ->missing('catalog.products.0.on_hand')
        ->missing('catalog.products.0.low_stock_threshold')
        ->missing('catalog.products.0.tracks_inventory')
        ->missing('catalog.products.0.version')
        ->missing('catalog.products.0.movements'));

    expect(app(BranchCatalog::class)->isAvailable($product, $branch))->toBe($expectedAvailable);
})->with([
    'tracked missing balance' => [true, null, 5, true, false, 'out_of_stock'],
    'tracked empty balance' => [true, 0, 5, true, false, 'out_of_stock'],
    'tracked stocked balance' => [true, 10, 5, true, true, 'in_stock'],
    'tracked low balance stays available' => [true, 5, 5, true, true, 'low_stock'],
    'stock cannot override branch disablement' => [true, 10, 5, false, false, 'in_stock'],
    'low stock cannot override branch disablement' => [true, 1, 5, false, false, 'low_stock'],
    'untracked no balance' => [false, null, null, true, true, 'not_tracked'],
    'untracked retained zero balance' => [false, 0, null, true, true, 'not_tracked'],
    'untracked respects branch disablement' => [false, null, null, false, false, 'not_tracked'],
]);

test('cashier branch switching isolates stock and ignores forged stock and branch query parameters', function () {
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $user = inventoryCashier($main);
    $user->branches()->attach($qave, ['is_active' => true]);
    $product = Product::factory()->create();
    BranchProduct::factory()->for($main)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
    BranchProduct::factory()->for($qave)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 2]);
    BranchInventory::factory()->for($main)->for($product)->create(['on_hand' => 0]);
    BranchInventory::factory()->for($qave)->for($product)->create(['on_hand' => 3]);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $main->id])
        ->get(route('workspaces.cashier', ['branch_id' => $qave->id, 'on_hand' => 100, 'is_available' => true]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.is_available', false)
            ->where('catalog.products.0.stock_status', 'out_of_stock'));
    $this->put(route('branch-context.update', $qave))->assertRedirectToRoute('workspace');
    $this->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->where('branchContext.current.id', $qave->id)
        ->where('catalog.products.0.is_available', true)
        ->where('catalog.products.0.stock_status', 'in_stock'));
});

test('open and closed cashier stock browsing never writes inventory or grants adjustment access', function (bool $open) {
    $branch = Branch::factory()->create();
    $user = inventoryCashier($branch);
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    if ($open) {
        StoreSession::factory()->for($branch)->create();
    }
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->get(route('workspaces.cashier', ['browse' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext.isOpen', $open)
            ->where('catalog.products.0.is_available', false)
            ->where('catalog.products.0.stock_status', 'out_of_stock'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->filter(fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query['query']) === 1))->toBeEmpty();
    $this->post(route('inventory.adjustments.store', [$branch, $product]), ['quantity_delta' => 10, 'reason' => 'Forged adjustment'])->assertForbidden();
    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['closed' => false, 'open' => true]);

test('availability refreshes persisted stock instead of trusting stale balances', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 1]);
    $product->load('inventoryBalances', 'branchProducts');
    $catalog = app(BranchCatalog::class);
    expect($catalog->isAvailable($product, $branch))->toBeTrue();
    $balance->update(['on_hand' => 0]);

    expect($catalog->isAvailable($product, $branch))->toBeFalse();
});

test('stock catalog query count stays bounded at realistic product counts', function (int $count) {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $products = Product::factory()->count($count)->create();
    foreach ($products as $product) {
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
        BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 3]);
        BranchInventory::factory()->for($other)->for($product)->create(['on_hand' => 0]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();

    $catalog = app(BranchCatalog::class)->browse($branch);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($catalog['products'])->toHaveCount($count);
    expect(count($queries))->toBeLessThanOrEqual(4);
    expect(collect($catalog['products'])->every(fn (array $product): bool => $product['stock_status'] === 'low_stock' && $product['is_available']))->toBeTrue();
    expect(collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], 'inventory_movements')))->toBeEmpty();
})->with([1, 30, 100]);
