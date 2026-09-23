<?php

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDevelopmentSeeder;
use Database\Seeders\LocalMenuCatalogSeeder;
use Database\Seeders\LocalModifierGroupSeeder;
use Illuminate\Support\Facades\Hash;

test('development accounts can log in and reach their assigned workspace', function (string $email, string $destination, int $branchCount) {
    $this->seed(LocalDevelopmentSeeder::class);

    $this->post(route('login.store'), [
        'email' => $email,
        'password' => 'password',
    ])->assertRedirect('/workspace');

    $this->assertAuthenticated();
    $this->get(route('workspace'))->assertRedirectToRoute($destination);

    $user = User::query()->where('email', $email)->sole();
    expect($user->branches()->wherePivot('is_active', true)->count())->toBe($branchCount);

    $this->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();
})->with([
    ['superadmin@gmail.com', 'workspaces.super-admin', 0],
    ['owner@gmail.com', 'workspaces.owner', 0],
    ['cashier@gmail.com', 'workspaces.cashier', 1],
    ['kitchen@gmail.com', 'workspaces.kitchen', 1],
    ['branch@gmail.com', 'branches.select', 2],
]);

test('development seeding can be repeated without duplicating records or resetting passwords', function () {
    $this->seed(LocalDevelopmentSeeder::class);
    $owner = User::query()->where('email', 'owner@gmail.com')->sole();
    $owner->update(['password' => 'changed-password']);
    $movementCount = InventoryMovement::query()->count();

    $this->seed(LocalDevelopmentSeeder::class);

    expect(User::query()->count())->toBe(5);
    expect(Branch::query()->count())->toBe(2);
    expect(Category::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['Menu', 'Silog', 'Lemon']);
    expect(Hash::check('changed-password', $owner->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('branch_tables', 5);
    $this->assertDatabaseCount('categories', 3);
    $this->assertDatabaseCount('products', 52);
    $this->assertDatabaseCount('branch_products', 104);
    $this->assertDatabaseCount('branch_inventory', 104);
    $this->assertDatabaseCount('modifier_groups', 5);
    $this->assertDatabaseCount('modifier_options', 9);
    $this->assertDatabaseCount('product_modifier_groups', 13);
    expect(InventoryMovement::query()->count())->toBe($movementCount);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('user_roles', 5);
    $this->assertDatabaseCount('user_branch_assignments', 4);
});

test('development seeding restores the proven local modifier groups and assignments without duplicates', function () {
    $this->seed(LocalDevelopmentSeeder::class);

    $expectedGroups = [
        'Egg options' => [null, 'single', 1, 1, ['Sunny side up' => '0.00', 'Scrambled' => '0.00']],
        'Rice' => [null, 'single', 1, 1, ['Regular' => '0.00', 'Extra rice' => '25.00']],
        'Size' => ['size', 'single', 1, 1, ['Medium' => '15.00', 'Large' => '30.00']],
        'Sugar level' => [null, 'single', 1, 1, ['50%' => '0.00', '25%' => '0.00']],
        'Add-ons' => [null, 'multiple', 0, 1, ['Pearls' => '15.00']],
    ];

    foreach ($expectedGroups as $name => [$semanticRole, $selectionType, $minimum, $maximum, $options]) {
        $group = ModifierGroup::query()->where('name', $name)->sole();

        expect($group->semantic_role?->value)->toBe($semanticRole)
            ->and($group->selection_type->value)->toBe($selectionType)
            ->and($group->min_select)->toBe($minimum)
            ->and($group->max_select)->toBe($maximum)
            ->and($group->options()->orderBy('sort_order')->pluck('price_delta', 'name')->all())->toBe($options);
    }

    foreach (['Tapsilog', 'Hotsilog', 'Chicksilog', 'Bangsilog', 'Longsilog'] as $productName) {
        expect(Product::query()->where('name', $productName)->sole()
            ->modifierGroups()->orderBy('name')->pluck('name')->all())
            ->toBe(['Egg options', 'Rice']);
    }
    expect(Product::query()->where('name', 'Lemon Yakult')->sole()
        ->modifierGroups()->orderBy('name')->pluck('name')->all())
        ->toBe(['Add-ons', 'Size', 'Sugar level']);
    $this->assertDatabaseCount('modifier_groups', 5);
    $this->assertDatabaseCount('modifier_options', 9);
    $this->assertDatabaseCount('product_modifier_groups', 13);

    $pearls = ModifierOption::query()->where('name', 'Pearls')->sole();
    $pearls->update(['price_delta' => '19.00']);
    $this->seed(LocalDevelopmentSeeder::class);

    expect($pearls->refresh()->price_delta)->toBe('19.00');
    $this->assertDatabaseCount('modifier_groups', 5);
    $this->assertDatabaseCount('modifier_options', 9);
    $this->assertDatabaseCount('product_modifier_groups', 13);
});

test('development seeding creates locally priced tracked menu inventory without attaching images', function () {
    $this->seed(LocalDevelopmentSeeder::class);

    expect(Category::query()->where('name', 'Silog')->sole()->products()->count())->toBe(17);
    expect(Category::query()->where('name', 'Lemon')->sole()->products()->count())->toBe(4);
    expect(Product::query()->whereNull('image_path')->count())->toBe(52);
    expect(Product::query()->where('default_price', '0')->count())->toBe(0);
    expect(Product::query()->where('is_active', false)->count())->toBe(0);
    expect(Product::query()->whereIn('name', ['Lemon Menu', 'Pongskilog Menu', 'Silog'])->count())->toBe(0);

    foreach ([
        'Beef Pares' => '120.00',
        'Tapsilog' => '95.00',
        'Lemon Yakult' => '55.00',
    ] as $productName => $expectedPrice) {
        expect(Product::query()->where('name', $productName)->sole()->default_price)->toBe($expectedPrice);
    }

    foreach (['MAIN' => 50, 'QAVE' => 30] as $branchCode => $expectedStock) {
        $branch = Branch::query()->where('code', $branchCode)->sole();

        expect(BranchProduct::query()->whereBelongsTo($branch)->count())->toBe(52);
        expect(BranchProduct::query()->whereBelongsTo($branch)->where('tracks_inventory', true)->count())->toBe(52);
        expect(BranchProduct::query()->whereBelongsTo($branch)->where('is_available', true)->count())->toBe(52);
        expect(BranchProduct::query()->whereBelongsTo($branch)->where('low_stock_threshold', 5)->count())->toBe(52);
        expect(BranchInventory::query()->whereBelongsTo($branch)->where('on_hand', $expectedStock)->count())->toBe(52);
        expect(InventoryMovement::query()
            ->whereBelongsTo($branch)
            ->where('movement_type', 'manual_adjustment')
            ->where('reason', 'Local POS QA setup')
            ->where('quantity_delta', $expectedStock)
            ->count())->toBe(52);
    }
});

test('development seeding preserves manual prices branch overrides and used stock', function () {
    $this->seed(LocalDevelopmentSeeder::class);
    $main = Branch::query()->where('code', 'MAIN')->sole();
    $tapsilog = Product::query()->where('name', 'Tapsilog')->sole();
    $configuration = BranchProduct::query()
        ->whereBelongsTo($main)
        ->whereBelongsTo($tapsilog)
        ->sole();
    $balance = BranchInventory::query()
        ->whereBelongsTo($main)
        ->whereBelongsTo($tapsilog)
        ->sole();
    $tapsilog->update(['default_price' => '123.45']);
    $configuration->update([
        'price_override' => '111.00',
        'is_available' => false,
        'tracks_inventory' => false,
        'low_stock_threshold' => 9,
    ]);
    $balance->update(['on_hand' => 40]);
    $movementCount = InventoryMovement::query()
        ->whereBelongsTo($main)
        ->whereBelongsTo($tapsilog)
        ->count();

    $this->seed(LocalDevelopmentSeeder::class);

    expect($tapsilog->refresh()->default_price)->toBe('123.45');
    expect($configuration->refresh()->only([
        'price_override', 'is_available', 'tracks_inventory', 'low_stock_threshold',
    ]))->toBe([
        'price_override' => '111.00',
        'is_available' => false,
        'tracks_inventory' => true,
        'low_stock_threshold' => 9,
    ]);
    expect($balance->refresh()->on_hand)->toBe(40);
    expect(InventoryMovement::query()
        ->whereBelongsTo($main)
        ->whereBelongsTo($tapsilog)
        ->count())->toBe($movementCount);
});

test('development seeding refuses production before creating data', function () {
    app()->instance('env', 'production');

    expect(fn () => app(LocalDevelopmentSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Development accounts may only be seeded locally or in tests.');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('branches', 0);
    $this->assertDatabaseCount('roles', 0);
    $this->assertDatabaseCount('branch_tables', 0);
});

test('local menu seeding refuses production before changing catalog data', function () {
    app()->instance('env', 'production');

    expect(fn () => app(LocalMenuCatalogSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Local menu catalog metadata may only be seeded locally or in tests.');

    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('branch_products', 0);
    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('local modifier fixture seeding refuses production before creating groups', function () {
    app()->instance('env', 'production');

    expect(fn () => app(LocalModifierGroupSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Local modifier fixtures may only be seeded locally or in tests.');

    $this->assertDatabaseCount('modifier_groups', 0);
    $this->assertDatabaseCount('modifier_options', 0);
    $this->assertDatabaseCount('product_modifier_groups', 0);
});

test('development accounts have the intended roles and active branch assignments with stores closed', function () {
    $this->seed(LocalDevelopmentSeeder::class);

    foreach ([
        'superadmin@gmail.com' => ['super_admin', []],
        'owner@gmail.com' => ['owner', []],
        'cashier@gmail.com' => ['cashier_kitchen', ['MAIN']],
        'kitchen@gmail.com' => ['kitchen_staff', ['MAIN']],
        'branch@gmail.com' => ['cashier', ['MAIN', 'QAVE']],
    ] as $email => [$role, $branches]) {
        $user = User::query()->where('email', $email)->sole();

        expect($user->is_active)->toBeTrue();
        expect($user->roles()->pluck('name')->all())->toBe([$role]);
        expect($user->branches()->wherePivot('is_active', true)->orderBy('code')->pluck('code')->all())
            ->toBe($branches);
    }

    expect(Branch::query()->where('status', 'active')->count())->toBe(2);
    $this->assertDatabaseCount('store_sessions', 0);
});

test('normal database seeding does not create development accounts or branches', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('branches', 0);
    $this->assertDatabaseCount('store_sessions', 0);
});
