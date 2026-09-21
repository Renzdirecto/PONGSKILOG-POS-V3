<?php

use App\Actions\Inventory\AdjustInventory;
use App\Enums\BranchStatus;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function inventoryManager(string $role = 'owner'): User
{
    test()->seed(RbacSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

test('authorized managers can view inventory and append audited signed adjustments', function (string $role) {
    $user = inventoryManager($role);
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $product = Product::factory()->create();
    BranchProduct::factory()->for($main)->for($product)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($qave)->for($product)->create(['tracks_inventory' => true]);
    $otherBalance = BranchInventory::factory()->for($qave)->for($product)->create(['on_hand' => 20, 'version' => 7]);
    $otherAttributes = $otherBalance->fresh()->getAttributes();

    $this->actingAs($user)->get(route('inventory.index', ['branch_id' => $main->id]))
        ->assertInertia(fn (Assert $page) => $page->component('inventory/index')->where('selectedBranch.id', $main->id));
    $this->post(route('inventory.adjustments.store', [$main, $product]), [
        'quantity_delta' => '+10', 'reason' => 'Opening count',
        'created_by_user_id' => User::factory()->create()->id, 'movement_type' => 'sale',
        'branch_id' => $qave->id, 'product_id' => Product::factory()->create()->id, 'on_hand' => 999,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('branch_inventory', ['branch_id' => $main->id, 'product_id' => $product->id, 'on_hand' => 10, 'version' => 1]);
    $this->assertDatabaseHas('inventory_movements', [
        'branch_id' => $main->id, 'product_id' => $product->id,
        'movement_type' => 'manual_adjustment', 'quantity_delta' => 10,
        'created_by_user_id' => $user->id, 'reason' => 'Opening count',
        'order_id' => null, 'store_session_expense_id' => null, 'stock_transfer_id' => null,
    ]);
    $firstMovement = InventoryMovement::query()->sole()->getAttributes();

    $this->post(route('inventory.adjustments.store', [$main, $product]), ['quantity_delta' => -3, 'reason' => 'Damaged item'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('branch_inventory', ['branch_id' => $main->id, 'product_id' => $product->id, 'on_hand' => 7, 'version' => 2]);
    $this->assertDatabaseHas('inventory_movements', ['quantity_delta' => -3, 'reason' => 'Damaged item', 'created_by_user_id' => $user->id]);
    $this->assertDatabaseCount('inventory_movements', 2);
    $this->assertDatabaseHas('inventory_movements', $firstMovement);
    expect($otherBalance->refresh()->getAttributes())->toBe($otherAttributes);
    $this->get(route('inventory.movements.index', [$main, $product]))
        ->assertInertia(fn (Assert $page) => $page->component('inventory/movements')->has('movements.data', 2));
})->with(['owner', 'super_admin']);

test('inventory reads and adjustment endpoints reject operational roles even with branch assignment', function (string $role) {
    $user = inventoryManager($role);
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $user->branches()->attach($branch, ['is_active' => true]);

    $this->actingAs($user)->get(route('inventory.index'))->assertForbidden();
    $this->get(route('inventory.movements.index', [$branch, $product]))->assertForbidden();
    $this->post(route('inventory.adjustments.store', [$branch, $product]), ['quantity_delta' => 10, 'reason' => 'Opening count'])->assertForbidden();

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('inventory management requires authentication on every endpoint', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();

    $this->get(route('inventory.index'))->assertRedirectToRoute('login');
    $this->get(route('inventory.movements.index', [$branch, $product]))->assertRedirectToRoute('login');
    $this->post(route('inventory.adjustments.store', [$branch, $product]), ['quantity_delta' => 10, 'reason' => 'Opening count'])->assertRedirectToRoute('login');

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('inactive managers are logged out on inventory reads and adjustments', function () {
    $user = inventoryManager();
    $user->forceFill(['is_active' => false])->save();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();

    $this->actingAs($user)->get(route('inventory.index'))->assertRedirectToRoute('login');
    $this->assertGuest();
    $this->actingAs($user)->get(route('inventory.movements.index', [$branch, $product]))->assertRedirectToRoute('login');
    $this->assertGuest();
    $this->actingAs($user)->post(route('inventory.adjustments.store', [$branch, $product]), ['quantity_delta' => 10, 'reason' => 'Opening count'])->assertRedirectToRoute('login');
    $this->assertGuest();
    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('revoked inventory permission blocks management even for business-wide roles', function (string $role) {
    $user = inventoryManager($role);
    $user->roles()->sole()->permissions()->detach(Permission::query()->where('name', 'inventory.manage')->sole());
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();

    $this->actingAs($user)->get(route('inventory.index'))->assertForbidden();
    $this->get(route('inventory.movements.index', [$branch, $product]))->assertForbidden();
    $this->post(route('inventory.adjustments.store', [$branch, $product]), ['quantity_delta' => 10, 'reason' => 'Opening count'])->assertForbidden();

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['owner', 'super_admin']);

test('direct adjustments recheck persisted actor authorization', function (string $denial) {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $user->load('roles.permissions');
    match ($denial) {
        'inactive' => User::query()->whereKey($user->id)->update(['is_active' => false]),
        'permission revoked' => $user->roles()->sole()->permissions()->detach(Permission::query()->where('name', 'inventory.manage')->sole()),
        'role revoked' => $user->roles()->detach(),
    };

    expect(fn () => app(AdjustInventory::class)->execute($user, $branch, $product, 10, 'Opening count'))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['inactive', 'permission revoked', 'role revoked']);

test('invalid adjustment inputs preserve balance and append no ledger entry', function (array $invalid, string $field) {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 2, 'version' => 3]);
    $attributes = $balance->fresh()->getAttributes();

    $this->actingAs($user)->post(route('inventory.adjustments.store', [$branch, $product]), array_replace([
        'quantity_delta' => 1, 'reason' => 'Physical count correction',
    ], $invalid))->assertSessionHasErrors($field);

    expect($balance->refresh()->getAttributes())->toBe($attributes);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    'overdraw' => [['quantity_delta' => -3], 'quantity_delta'],
    'zero delta' => [['quantity_delta' => 0], 'quantity_delta'],
    'missing delta' => [['quantity_delta' => null], 'quantity_delta'],
    'fractional delta' => [['quantity_delta' => 1.5], 'quantity_delta'],
    'whole float delta' => [['quantity_delta' => 1.0], 'quantity_delta'],
    'boolean delta' => [['quantity_delta' => true], 'quantity_delta'],
    'malformed delta' => [['quantity_delta' => 'abc'], 'quantity_delta'],
    'missing reason' => [['reason' => null], 'reason'],
    'empty reason' => [['reason' => '   '], 'reason'],
    'overlong reason' => [['reason' => str_repeat('x', 1001)], 'reason'],
]);

test('untracked products reject adjustments without changing configuration or creating balances', function (bool $configured) {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    if ($configured) {
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => false]);
    }

    $this->actingAs($user)->post(route('inventory.adjustments.store', [$branch, $product]), ['quantity_delta' => 10, 'reason' => 'Opening count'])
        ->assertSessionHasErrors(['product_id' => 'Inventory is not tracked for this product at this branch.']);

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('branch_products', $configured ? 1 : 0);
})->with(['no branch configuration' => false, 'tracking disabled' => true]);

test('direct adjustments require a nonzero quantity and a bounded meaningful reason', function (int $delta, string $reason) {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);

    expect(fn () => app(AdjustInventory::class)->execute($user, $branch, $product, $delta, $reason))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    'zero' => [0, 'Opening count'],
    'blank reason' => [10, '   '],
    'overlong reason' => [10, str_repeat('x', 1001)],
]);

test('management stock uses only the selected branch configuration and does not fabricate balances', function () {
    $user = inventoryManager();
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $product = Product::factory()->create(['name' => 'A tracked meal', 'is_active' => false]);
    Product::factory()->create(['name' => 'B untracked meal']);
    BranchProduct::factory()->for($main)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
    BranchProduct::factory()->for($qave)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 2]);
    BranchInventory::factory()->for($qave)->for($product)->create(['on_hand' => 3]);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $main->id])->get(route('inventory.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedBranch.id', $main->id)
            ->has('products.data', 2)
            ->where('products.data.0.tracked', true)
            ->where('products.data.0.on_hand', 0)
            ->where('products.data.0.low_stock_threshold', 5)
            ->where('products.data.0.status', 'out_of_stock')
            ->where('products.data.1.tracked', false)
            ->where('products.data.1.on_hand', null)
            ->where('products.data.1.status', 'not_tracked'));
    $this->get(route('inventory.index', ['branch_id' => $qave->id]))->assertInertia(fn (Assert $page) => $page
        ->where('selectedBranch.id', $qave->id)
        ->where('products.data.0.on_hand', 3)
        ->where('products.data.0.low_stock_threshold', 2)
        ->where('products.data.0.status', 'in_stock'));

    $this->assertDatabaseCount('branch_inventory', 1);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('inventory managers can explicitly select or retain the current non-active branch', function (BranchStatus $status, bool $explicit) {
    $user = inventoryManager();
    $active = Branch::factory()->create(['code' => 'A-ACTIVE']);
    $retained = Branch::factory()->create(['code' => 'B-RETAINED', 'status' => $status]);
    $product = Product::factory()->create();
    BranchProduct::factory()->for($active)->for($product)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($retained)->for($product)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($active)->for($product)->create(['on_hand' => 100]);
    BranchInventory::factory()->for($retained)->for($product)->create(['on_hand' => 7]);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $explicit ? $active->id : $retained->id])
        ->get(route('inventory.index', $explicit ? ['branch_id' => $retained->id] : []))
        ->assertInertia(fn (Assert $page) => $page
            ->has('branches', 2)
            ->where('branches.1.id', $retained->id)
            ->where('selectedBranch.id', $retained->id)
            ->where('products.data.0.on_hand', 7));
})->with([
    'explicit inactive' => [BranchStatus::Inactive, true],
    'current inactive' => [BranchStatus::Inactive, false],
    'explicit temporarily closed' => [BranchStatus::TemporarilyClosed, true],
    'current temporarily closed' => [BranchStatus::TemporarilyClosed, false],
]);

test('inventory defaults prefer an active branch and fall back to retained branches when none are active', function (bool $hasActiveBranch) {
    $user = inventoryManager();
    $retained = Branch::factory()->create(['code' => 'A-RETAINED', 'status' => BranchStatus::Inactive]);
    $other = Branch::factory()->create(['code' => 'Z-OTHER', 'status' => $hasActiveBranch ? BranchStatus::Active : BranchStatus::TemporarilyClosed]);
    Product::factory()->create();

    $this->actingAs($user)->get(route('inventory.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('branches', 2)
            ->where('selectedBranch.id', $hasActiveBranch ? $other->id : $retained->id)
            ->has('products.data', 1));
})->with(['active branch available' => true, 'all branches retained' => false]);

test('management stock filters include missing balances and respect threshold boundaries', function (string $status, array $expectedNames) {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $category = Category::factory()->create();
    foreach ([['A stocked', 6], ['B low', 5], ['C empty', 0], ['D missing', null]] as [$name, $quantity]) {
        $product = Product::factory()->for($category)->create(['name' => $name]);
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
        if ($quantity !== null) {
            BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $quantity]);
        }
    }
    Product::factory()->for($category)->create(['name' => 'E untracked']);

    $response = $this->actingAs($user)->get(route('inventory.index', ['branch_id' => $branch->id, 'stock_status' => $status]));

    expect(collect($response->inertiaProps('products.data'))->pluck('name')->all())->toBe($expectedNames);
})->with([
    'all' => ['all', ['A stocked', 'B low', 'C empty', 'D missing', 'E untracked']],
    'in stock' => ['in_stock', ['A stocked']],
    'low stock' => ['low_stock', ['B low']],
    'out of stock' => ['out_of_stock', ['C empty', 'D missing']],
    'not tracked' => ['not_tracked', ['E untracked']],
]);

test('inventory summaries use the full branch dataset and category filters before pagination', function () {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $meals = Category::factory()->create(['name' => 'Meals']);
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    foreach ([
        ['A stocked', $meals, 8],
        ['B low', $meals, 3],
        ['C empty', $meals, 0],
        ['D drink', $drinks, 8],
    ] as [$name, $category, $quantity]) {
        $product = Product::factory()->for($category)->create(['name' => $name]);
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
        BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $quantity]);
    }
    Product::factory()->for($meals)->count(25)->create()->each(
        fn (Product $product) => BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => false]),
    );

    $this->actingAs($user)->get(route('inventory.index', ['branch_id' => $branch->id, 'category' => $meals->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category', $meals->id)
            ->has('categories', 2)
            ->where('summary.in_stock', 1)
            ->where('summary.low_stock', 1)
            ->where('summary.out_of_stock', 1)
            ->where('summary.not_tracked', 25)
            ->where('products.total', 28)
            ->has('products.data', 24));
});

test('management search pagination and image signing operate only on the current page', function () {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $category = Category::factory()->create();
    $products = Product::factory()->for($category)->count(26)->sequence(fn ($sequence) => ['name' => sprintf('Meal %02d', $sequence->index)])->create();
    foreach ($products as $product) {
        $product->update(['image_path' => 'catalog/products/'.$product->id.'/'.Str::uuid().'/detail.webp']);
    }
    $signedPaths = [];
    Storage::fake('s3')->buildTemporaryUrlsUsing(function (string $path) use (&$signedPaths): string {
        $signedPaths[] = $path;

        return 'https://assets.example.test/'.$path;
    });

    $this->actingAs($user)->get(route('inventory.index', ['branch_id' => $branch->id]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 24)->where('products.total', 26)->where('products.last_page', 2)
        ->where('products.data.0.category_name', $category->name)
        ->where('products.data.0.image_url', 'https://assets.example.test/'.dirname($products[0]->image_path).'/card.webp')
        ->missing('products.data.0.image_path')->missing('products.data.0.detail_url')->missing('products.data.0.source_url'));

    expect($signedPaths)->toHaveCount(24);
    expect(collect($signedPaths)->every(fn (string $path): bool => str_ends_with($path, '/card.webp')))->toBeTrue();
    $this->get(route('inventory.index', ['branch_id' => $branch->id, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 2)->where('products.data.0.name', 'Meal 24'));
    $this->get(route('inventory.index', ['branch_id' => $branch->id, 'search' => 'meal 25']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', 'Meal 25'));
});

test('management listing never queries per-product movement history and stays bounded with more products', function () {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    Product::factory()->create();
    $this->actingAs($user);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('inventory.index', ['branch_id' => $branch->id]))->assertOk();
    $initialCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    Product::factory()->count(29)->create();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('inventory.index', ['branch_id' => $branch->id]))->assertInertia(fn (Assert $page) => $page->has('products.data', 24));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBe($initialCount);
    expect(collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], 'inventory_movements')))->toBeEmpty();
});

test('history is scoped by branch and product paginated newest first and exposes only readable audit fields', function () {
    $user = inventoryManager();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $this->freezeTime();
    InventoryMovement::factory()->for($branch)->for($product)->count(30)->create(['created_at' => now()->subDay()]);
    $latest = InventoryMovement::factory()->for($branch)->for($product)->create([
        'quantity_delta' => -3, 'reason' => 'Damaged item', 'created_by_user_id' => $user->id,
        'created_at' => now(), 'order_id' => (string) Str::uuid(),
    ]);
    InventoryMovement::factory()->for($product)->create(['created_at' => now()->addHour()]);
    InventoryMovement::factory()->for($branch)->create(['created_at' => now()->addHour()]);

    $this->actingAs($user)->get(route('inventory.movements.index', [$branch, $product]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/movements')
            ->where('branch.id', $branch->id)->where('product.id', $product->id)
            ->has('movements.data', 30)->where('movements.total', 31)->where('movements.last_page', 2)
            ->where('movements.data.0.id', $latest->id)
            ->where('movements.data.0.movement_type', 'manual_adjustment')
            ->where('movements.data.0.movement_label', 'Manual Adjustment')
            ->where('movements.data.0.quantity_delta', -3)
            ->where('movements.data.0.reason', 'Damaged item')
            ->where('movements.data.0.created_by_name', $user->name)
            ->missing('movements.data.0.order_id')->missing('movements.data.0.store_session_expense_id')
            ->missing('movements.data.0.stock_transfer_id')->missing('movements.data.0.created_by.email'));
    $this->get(route('inventory.movements.index', [$branch, $product, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 1)->where('movements.current_page', 2));
});

test('history labels preserve the frozen movement types and allow missing actors', function (InventoryMovementType $type, string $label) {
    $user = inventoryManager();
    $movement = InventoryMovement::factory()->create(['movement_type' => $type, 'created_by_user_id' => null]);

    $this->actingAs($user)->get(route('inventory.movements.index', [$movement->branch_id, $movement->product_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('movements.data.0.movement_type', $type->value)
            ->where('movements.data.0.movement_label', $label)
            ->where('movements.data.0.created_by_name', null));
})->with([
    [InventoryMovementType::Sale, 'Sale'],
    [InventoryMovementType::PayLaterCommit, 'Pay Later'],
    [InventoryMovementType::OrderEditDelta, 'Order Edit'],
    [InventoryMovementType::VoidRestore, 'Void Restore'],
    [InventoryMovementType::ManualAdjustment, 'Manual Adjustment'],
    [InventoryMovementType::StorePurchaseRestock, 'Store Purchase Restock'],
    [InventoryMovementType::TransferOut, 'Transfer Out'],
    [InventoryMovementType::TransferIn, 'Transfer In'],
]);

test('inventory history cannot be updated or deleted through direct management requests', function (string $method) {
    $user = inventoryManager();
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $movement = app(AdjustInventory::class)->execute($user, $configuration->branch, $configuration->product, 5, 'Opening count');
    $originalMovement = $movement->refresh()->getAttributes();
    $originalBalance = BranchInventory::query()->sole()->getAttributes();
    $history = route('inventory.movements.index', [$configuration->branch, $configuration->product]);
    $adjustment = route('inventory.adjustments.store', [$configuration->branch, $configuration->product]);

    $this->actingAs($user)->json($method, $history, ['quantity_delta' => 999, 'reason' => 'Rewrite'])->assertMethodNotAllowed();
    $this->json($method, $history.'/'.$movement->id, ['quantity_delta' => 999])->assertNotFound();
    $this->json($method, $adjustment, ['quantity_delta' => 999, 'reason' => 'Rewrite'])->assertMethodNotAllowed();

    expect(InventoryMovement::query()->sole()->getAttributes())->toBe($originalMovement);
    expect(BranchInventory::query()->sole()->getAttributes())->toBe($originalBalance);
})->with(['PUT', 'PATCH', 'DELETE']);
