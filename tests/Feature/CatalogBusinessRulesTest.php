<?php

use App\Actions\Catalog\CreateCategory;
use App\Actions\Catalog\CreateModifierGroup;
use App\Actions\Catalog\CreateModifierOption;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\SyncProductModifierGroups;
use App\Actions\Catalog\UpdateCategory;
use App\Actions\Catalog\UpdateModifierGroup;
use App\Actions\Catalog\UpdateModifierOption;
use App\Actions\Catalog\UpdateProduct;
use App\Actions\Catalog\UpsertBranchProduct;
use App\Enums\ModifierSelectionType;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function catalogUser(string $role = 'owner'): User
{
    test()->seed(RbacSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function assertCatalogValidation(Closure $mutation, string $field): void
{
    try {
        $mutation();
        test()->fail('Invalid catalog input was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        expect($exception->errors()[$field])->not->toBeEmpty();
    }
}

test('catalog managers can create and update categories products and modifier configuration', function (string $role) {
    $user = catalogUser($role);
    $branch = Branch::factory()->create();

    $category = app(CreateCategory::class)->execute($user, [
        'name' => 'Silog', 'sort_order' => 0, 'is_active' => true,
    ]);
    $this->assertDatabaseHas('categories', [
        'id' => $category->id, 'name' => 'Silog', 'sort_order' => 0, 'is_active' => true,
    ]);
    app(UpdateCategory::class)->execute($user, $category, [
        'name' => 'Breakfast', 'sort_order' => 2, 'is_active' => false,
    ]);
    $this->assertDatabaseHas('categories', [
        'id' => $category->id, 'name' => 'Breakfast', 'sort_order' => 2, 'is_active' => false,
    ]);

    $product = app(CreateProduct::class)->execute($user, [
        'category_id' => $category->id, 'name' => 'Pork Silog', 'description' => null,
        'default_price' => '95.10', 'is_active' => true, 'image_path' => 'untrusted.jpg',
    ])->refresh();
    expect($product->default_price)->toBe('95.10');
    expect($product->description)->toBeNull();
    expect($product->image_path)->toBeNull();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Pork Silog', 'is_active' => true]);

    $product->update(['image_path' => 'products/existing.webp']);
    $otherCategory = Category::factory()->create();
    $product->image_path = 'dirty-unsaved.jpg';
    app(UpdateProduct::class)->execute($user, $product, [
        'category_id' => $otherCategory->id, 'name' => 'Chicken Silog', 'description' => 'With rice',
        'default_price' => '99.9', 'is_active' => false, 'image_path' => 'replacement.jpg',
    ]);
    expect($product->refresh()->default_price)->toBe('99.90');
    expect($product->image_path)->toBe('products/existing.webp');
    $this->assertDatabaseHas('products', [
        'id' => $product->id, 'category_id' => $otherCategory->id,
        'name' => 'Chicken Silog', 'description' => 'With rice', 'is_active' => false,
    ]);

    $group = app(CreateModifierGroup::class)->execute($user, [
        'name' => 'Egg', 'selection_type' => ModifierSelectionType::Single,
        'min_select' => 0, 'max_select' => 1, 'is_active' => true,
    ])->refresh();
    expect($group->selection_type)->toBe(ModifierSelectionType::Single);
    $this->assertDatabaseHas('modifier_groups', [
        'id' => $group->id, 'name' => 'Egg', 'min_select' => 0, 'max_select' => 1, 'is_active' => true,
    ]);
    app(UpdateModifierGroup::class)->execute($user, $group, [
        'name' => 'Extras', 'selection_type' => 'multiple',
        'min_select' => 1, 'max_select' => 3, 'is_active' => false,
    ]);
    expect($group->refresh()->selection_type)->toBe(ModifierSelectionType::Multiple);
    $this->assertDatabaseHas('modifier_groups', [
        'id' => $group->id, 'name' => 'Extras', 'min_select' => 1, 'max_select' => 3, 'is_active' => false,
    ]);

    $option = app(CreateModifierOption::class)->execute($user, [
        'modifier_group_id' => $group->id, 'name' => 'Extra Egg', 'price_delta' => '10.25',
        'is_active' => true, 'sort_order' => 0,
    ])->refresh();
    expect($option->price_delta)->toBe('10.25');
    $this->assertDatabaseHas('modifier_options', [
        'id' => $option->id, 'name' => 'Extra Egg', 'is_active' => true, 'sort_order' => 0,
    ]);
    $otherGroup = ModifierGroup::factory()->create();
    app(UpdateModifierOption::class)->execute($user, $option, [
        'modifier_group_id' => $otherGroup->id, 'name' => 'Extra Rice', 'price_delta' => '0',
        'is_active' => false, 'sort_order' => 3,
    ]);
    expect($option->refresh()->price_delta)->toBe('0.00');
    $this->assertDatabaseHas('modifier_options', [
        'id' => $option->id, 'modifier_group_id' => $otherGroup->id,
        'name' => 'Extra Rice', 'is_active' => false, 'sort_order' => 3,
    ]);

    $override = app(UpsertBranchProduct::class)->execute($user, $branch, $product, [
        'price_override' => '101.25', 'is_available' => false,
        'tracks_inventory' => true, 'low_stock_threshold' => 5,
    ])->refresh();
    expect($override->price_override)->toBe('101.25');
    expect($override->tracks_inventory)->toBeTrue();
    expect($override->low_stock_threshold)->toBe(5);
    expect($override->is_available)->toBeFalse();
    $this->assertDatabaseHas('branch_products', [
        'id' => $override->id, 'branch_id' => $branch->id, 'product_id' => $product->id,
    ]);

    app(SyncProductModifierGroups::class)->execute($user, $product, [$group->id, $otherGroup->id]);
    expect($product->modifierGroups()->pluck('modifier_groups.id')->all())
        ->toEqualCanonicalizing([$group->id, $otherGroup->id]);
})->with(['owner', 'super_admin']);

test('every catalog mutation rejects unauthorized actors without changing records', function (string $actor) {
    $role = match ($actor) {
        'inactive super admin', 'revoked super admin' => 'super_admin',
        'cashier', 'kitchen_staff', 'cashier_kitchen' => $actor,
        default => 'owner',
    };
    $user = catalogUser($role);
    if (str_starts_with($actor, 'inactive')) {
        User::query()->whereKey($user->id)->update(['is_active' => false]);
    }
    if (str_starts_with($actor, 'revoked')) {
        $user->roles()->sole()->permissions()->detach(Permission::query()->where('name', 'products.manage')->sole());
    }
    if ($actor === 'deleted') {
        $user->roles()->detach();
        User::query()->whereKey($user->id)->delete();
    }
    if ($actor === 'unsaved') {
        $user = User::factory()->make();
    }
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();
    $group = ModifierGroup::factory()->create();
    $option = ModifierOption::factory()->for($group, 'modifierGroup')->create();
    $branch = Branch::factory()->create();
    $override = BranchProduct::factory()->for($branch)->for($product)->create();
    $product->modifierGroups()->attach($group);
    $categoryInput = ['name' => 'Changed', 'sort_order' => 1, 'is_active' => false];
    $productInput = ['category_id' => $category->id, 'name' => 'Changed', 'default_price' => '0', 'is_active' => false];
    $groupInput = ['name' => 'Changed', 'selection_type' => 'multiple', 'min_select' => 0, 'max_select' => 2, 'is_active' => false];
    $optionInput = ['modifier_group_id' => $group->id, 'name' => 'Changed', 'price_delta' => '0', 'sort_order' => 1, 'is_active' => false];
    $originals = collect([$category, $product, $group, $option, $override])->map->refresh()->map->getAttributes();

    foreach ([
        fn () => app(CreateCategory::class)->execute($user, $categoryInput),
        fn () => app(UpdateCategory::class)->execute($user, $category, $categoryInput),
        fn () => app(CreateProduct::class)->execute($user, $productInput),
        fn () => app(UpdateProduct::class)->execute($user, $product, $productInput),
        fn () => app(CreateModifierGroup::class)->execute($user, $groupInput),
        fn () => app(UpdateModifierGroup::class)->execute($user, $group, $groupInput),
        fn () => app(CreateModifierOption::class)->execute($user, $optionInput),
        fn () => app(UpdateModifierOption::class)->execute($user, $option, $optionInput),
        fn () => app(UpsertBranchProduct::class)->execute($user, $branch, $product, [
            'price_override' => '0', 'is_available' => false, 'tracks_inventory' => true, 'low_stock_threshold' => 0,
        ]),
        fn () => app(SyncProductModifierGroups::class)->execute($user, $product, []),
    ] as $mutation) {
        expect($mutation)->toThrow(AuthorizationException::class);
    }

    foreach ([$category, $product, $group, $option, $override] as $index => $model) {
        expect($model->refresh()->getAttributes())->toBe($originals[$index]);
        $this->assertDatabaseCount($model->getTable(), 1);
    }
    $this->assertDatabaseCount('product_modifier_groups', 1);
})->with([
    'cashier', 'kitchen_staff', 'cashier_kitchen', 'inactive owner',
    'inactive super admin', 'revoked owner', 'revoked super admin', 'deleted', 'unsaved',
]);

test('guests cannot pass the catalog gate', function () {
    expect(fn () => Gate::forUser(null)->authorize('products.manage'))->toThrow(AuthorizationException::class);
});

test('category mutations validate their fields without partial writes', function (bool $update, string $field, mixed $value) {
    $user = catalogUser();
    $category = Category::factory()->create();
    $original = $category->refresh()->getAttributes();
    $input = array_replace(['name' => 'Silog', 'sort_order' => 0, 'is_active' => true], [$field => $value]);

    assertCatalogValidation(fn () => $update
        ? app(UpdateCategory::class)->execute($user, $category, $input)
        : app(CreateCategory::class)->execute($user, $input), $field);

    expect($category->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('categories', 1);
})->with([false, true])->with([
    'missing name' => ['name', null],
    'blank name' => ['name', '   '],
    'long name' => ['name', str_repeat('a', 256)],
    'nonstring name' => ['name', 42],
    'negative sort' => ['sort_order', -1],
    'fractional sort' => ['sort_order', '1.5'],
    'overflow sort' => ['sort_order', '2147483648'],
    'missing sort' => ['sort_order', null],
    'invalid active flag' => ['is_active', 'yes'],
    'missing active flag' => ['is_active', null],
]);

test('product mutations validate category and descriptive fields', function (bool $update, string $field, mixed $value) {
    $user = catalogUser();
    $product = Product::factory()->create();
    $original = $product->refresh()->getAttributes();
    $input = array_replace([
        'category_id' => $product->category_id, 'name' => 'Silog', 'description' => null,
        'default_price' => '95.00', 'is_active' => true,
    ], [$field => $value]);

    assertCatalogValidation(fn () => $update
        ? app(UpdateProduct::class)->execute($user, $product, $input)
        : app(CreateProduct::class)->execute($user, $input), $field);

    expect($product->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('products', 1);
})->with([false, true])->with([
    'missing category' => ['category_id', null],
    'unknown category' => ['category_id', '00000000-0000-4000-8000-000000000000'],
    'malformed category' => ['category_id', 'wrong'],
    'blank name' => ['name', ''],
    'long name' => ['name', str_repeat('a', 256)],
    'invalid description' => ['description', []],
    'long description' => ['description', str_repeat('a', 5001)],
    'invalid active flag' => ['is_active', 'yes'],
]);

dataset('invalid catalog money', [
    'missing' => [null],
    'empty' => [''],
    'negative' => ['-0.01'],
    'excess precision' => ['1.001'],
    'malformed' => ['abc'],
    'exponent' => ['1e2'],
    'comma' => ['1,000.00'],
    'leading whitespace' => [' 1.00'],
    'newline' => ["1.00\n"],
    'overflow' => ['1000000000000.00'],
    'float' => [95.25],
    'integer' => [95],
    'boolean' => [true],
    'array' => [[]],
    'plus sign' => ['+1.00'],
]);

test('product prices require exact nonnegative decimal strings', function (bool $update, mixed $price) {
    $user = catalogUser();
    $product = Product::factory()->create();
    $original = $product->refresh()->getAttributes();
    $input = ['category_id' => $product->category_id, 'name' => 'Changed', 'default_price' => $price, 'is_active' => true];

    assertCatalogValidation(fn () => $update
        ? app(UpdateProduct::class)->execute($user, $product, $input)
        : app(CreateProduct::class)->execute($user, $input), 'default_price');

    expect($product->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('products', 1);
})->with([false, true])->with('invalid catalog money');

test('modifier group mutations enforce valid selection types and bounds', function (bool $update, string $field, mixed $value) {
    $user = catalogUser();
    $group = ModifierGroup::factory()->create();
    $original = $group->refresh()->getAttributes();
    $input = array_replace([
        'name' => 'Extras', 'selection_type' => 'multiple', 'min_select' => 1, 'max_select' => 2, 'is_active' => true,
    ], [$field => $value]);

    assertCatalogValidation(fn () => $update
        ? app(UpdateModifierGroup::class)->execute($user, $group, $input)
        : app(CreateModifierGroup::class)->execute($user, $input), $field);

    expect($group->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('modifier_groups', 1);
})->with([false, true])->with([
    'blank name' => ['name', ''],
    'long name' => ['name', str_repeat('a', 256)],
    'invalid selection' => ['selection_type', 'any'],
    'missing selection' => ['selection_type', null],
    'negative minimum' => ['min_select', -1],
    'fractional minimum' => ['min_select', '0.5'],
    'overflow minimum' => ['min_select', '2147483648'],
    'missing minimum' => ['min_select', null],
    'negative maximum' => ['max_select', -1],
    'fractional maximum' => ['max_select', '1.5'],
    'overflow maximum' => ['max_select', '2147483648'],
    'missing maximum' => ['max_select', null],
    'inverted bounds' => ['max_select', 0],
    'invalid active flag' => ['is_active', 'yes'],
]);

test('zero modifier selection bounds are valid', function () {
    $group = app(CreateModifierGroup::class)->execute(catalogUser(), [
        'name' => 'Optional', 'selection_type' => 'multiple', 'min_select' => 0, 'max_select' => 0, 'is_active' => true,
    ])->refresh();

    expect($group->min_select)->toBe(0);
    expect($group->max_select)->toBe(0);
});

test('modifier option mutations validate group name sorting and active flag', function (bool $update, string $field, mixed $value) {
    $user = catalogUser();
    $option = ModifierOption::factory()->create();
    $original = $option->refresh()->getAttributes();
    $input = array_replace([
        'modifier_group_id' => $option->modifier_group_id, 'name' => 'Egg', 'price_delta' => '10.00',
        'sort_order' => 0, 'is_active' => true,
    ], [$field => $value]);

    assertCatalogValidation(fn () => $update
        ? app(UpdateModifierOption::class)->execute($user, $option, $input)
        : app(CreateModifierOption::class)->execute($user, $input), $field);

    expect($option->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('modifier_options', 1);
})->with([false, true])->with([
    'missing group' => ['modifier_group_id', null],
    'unknown group' => ['modifier_group_id', '00000000-0000-4000-8000-000000000000'],
    'malformed group' => ['modifier_group_id', 'wrong'],
    'blank name' => ['name', ''],
    'long name' => ['name', str_repeat('a', 256)],
    'negative sort' => ['sort_order', -1],
    'fractional sort' => ['sort_order', '0.5'],
    'overflow sort' => ['sort_order', '2147483648'],
    'invalid active flag' => ['is_active', 'yes'],
]);

test('modifier prices require exact nonnegative decimal strings', function (bool $update, mixed $price) {
    $user = catalogUser();
    $option = ModifierOption::factory()->create();
    $original = $option->refresh()->getAttributes();
    $input = [
        'modifier_group_id' => $option->modifier_group_id, 'name' => 'Changed', 'price_delta' => $price,
        'is_active' => true, 'sort_order' => 0,
    ];

    assertCatalogValidation(fn () => $update
        ? app(UpdateModifierOption::class)->execute($user, $option, $input)
        : app(CreateModifierOption::class)->execute($user, $input), 'price_delta');

    expect($option->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('modifier_options', 1);
})->with([false, true])->with('invalid catalog money');

test('branch override upsert preserves one row and can clear optional configuration', function () {
    $user = catalogUser();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $action = app(UpsertBranchProduct::class);
    $original = $action->execute($user, $branch, $product, [
        'price_override' => '99.00', 'is_available' => false, 'tracks_inventory' => true, 'low_stock_threshold' => 0,
    ]);
    $otherBranch = Branch::factory()->create();
    $otherProduct = Product::factory()->create();

    $updated = $action->execute($user, $branch, $product, [
        'price_override' => null, 'is_available' => true, 'tracks_inventory' => false, 'low_stock_threshold' => null,
        'branch_id' => $otherBranch->id, 'product_id' => $otherProduct->id,
    ])->refresh();

    expect($updated->id)->toBe($original->id);
    expect($updated->price_override)->toBeNull();
    expect($updated->low_stock_threshold)->toBeNull();
    expect($updated->tracks_inventory)->toBeFalse();
    expect($updated->is_available)->toBeTrue();
    expect($updated->branch_id)->toBe($branch->id);
    expect($updated->product_id)->toBe($product->id);
    $this->assertDatabaseCount('branch_products', 1);
});

test('invalid branch override configuration leaves existing and absent overrides unchanged', function (bool $existing, string $field, mixed $value) {
    $user = catalogUser();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $override = $existing ? BranchProduct::factory()->for($branch)->for($product)->create() : null;
    $original = $override?->refresh()->getAttributes();
    $input = array_replace([
        'price_override' => null, 'is_available' => false, 'tracks_inventory' => true, 'low_stock_threshold' => 5,
    ], [$field => $value]);

    assertCatalogValidation(fn () => app(UpsertBranchProduct::class)->execute($user, $branch, $product, $input), $field);

    expect($override?->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('branch_products', $existing ? 1 : 0);
})->with([false, true])->with([
    'negative threshold' => ['low_stock_threshold', -1],
    'fractional threshold' => ['low_stock_threshold', '1.5'],
    'malformed threshold' => ['low_stock_threshold', 'many'],
    'empty threshold' => ['low_stock_threshold', ''],
    'overflow threshold' => ['low_stock_threshold', '2147483648'],
    'invalid availability' => ['is_available', 'yes'],
    'missing availability' => ['is_available', null],
    'invalid tracking' => ['tracks_inventory', 'yes'],
    'missing tracking' => ['tracks_inventory', null],
    'negative price' => ['price_override', '-0.01'],
    'malformed price' => ['price_override', 'one'],
    'price precision' => ['price_override', '99.001'],
    'price overflow' => ['price_override', '1000000000000.00'],
    'price float' => ['price_override', 99.25],
    'price exponent' => ['price_override', '1e2'],
    'price empty' => ['price_override', ''],
]);

test('all catalog money fields accept exact decimal boundaries', function (string $price, string $expected) {
    $user = catalogUser();
    $category = Category::factory()->create();
    $group = ModifierGroup::factory()->create();
    $branch = Branch::factory()->create();

    $product = app(CreateProduct::class)->execute($user, [
        'category_id' => $category->id, 'name' => 'Silog', 'default_price' => $price, 'is_active' => true,
    ])->refresh();
    $option = app(CreateModifierOption::class)->execute($user, [
        'modifier_group_id' => $group->id, 'name' => 'Egg', 'price_delta' => $price, 'is_active' => true, 'sort_order' => 0,
    ])->refresh();
    $override = app(UpsertBranchProduct::class)->execute($user, $branch, $product, [
        'price_override' => $price, 'is_available' => true, 'tracks_inventory' => false, 'low_stock_threshold' => null,
    ])->refresh();

    expect($product->default_price)->toBe($expected);
    expect($option->price_delta)->toBe($expected);
    expect($override->price_override)->toBe($expected);
})->with([
    ['0', '0.00'], ['0.01', '0.01'], ['12.3', '12.30'],
    ['123456789.12', '123456789.12'], ['999999999999.99', '999999999999.99'],
]);

test('branch overrides reject nonexistent branch or product records', function (string $missing) {
    $user = catalogUser();
    $branch = $missing === 'branch' ? new Branch(['id' => (string) Str::uuid()]) : Branch::factory()->create();
    $product = $missing === 'product' ? new Product(['id' => (string) Str::uuid()]) : Product::factory()->create();

    expect(fn () => app(UpsertBranchProduct::class)->execute($user, $branch, $product, [
        'price_override' => null, 'is_available' => true, 'tracks_inventory' => false, 'low_stock_threshold' => null,
    ]))->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseCount('branch_products', 0);
})->with(['branch', 'product']);

test('modifier group sync deduplicates replaces and clears only the target product mappings', function () {
    $user = catalogUser();
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $groups = ModifierGroup::factory()->count(3)->create();
    $otherProduct->modifierGroups()->attach($groups[0]);
    $action = app(SyncProductModifierGroups::class);

    $action->execute($user, $product, [$groups[0]->id, $groups[1]->id, $groups[1]->id]);
    $action->execute($user, $product, [$groups[0]->id, $groups[1]->id, $groups[1]->id]);
    expect($product->modifierGroups()->count())->toBe(2);
    $action->execute($user, $product, [$groups[2]->id]);
    expect($product->modifierGroups()->sole()->is($groups[2]))->toBeTrue();
    $action->execute($user, $product, []);

    expect($product->modifierGroups()->count())->toBe(0);
    expect($otherProduct->modifierGroups()->sole()->is($groups[0]))->toBeTrue();
    $this->assertDatabaseCount('product_modifier_groups', 1);
});

test('invalid modifier IDs cannot detach existing mappings', function (mixed $id) {
    $user = catalogUser();
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create();
    $product->modifierGroups()->attach($group);

    assertCatalogValidation(fn () => app(SyncProductModifierGroups::class)->execute($user, $product, [$id]), 'modifier_group_ids.0');

    expect($product->modifierGroups()->sole()->is($group))->toBeTrue();
})->with([
    ['00000000-0000-4000-8000-000000000000'], ['wrong'], [null], [['id' => 'wrong']],
]);

test('modifier sync rejects associative pivot payloads', function () {
    $user = catalogUser();
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create();

    assertCatalogValidation(fn () => app(SyncProductModifierGroups::class)->execute($user, $product, [
        $group->id => ['modifier_group_id' => $group->id],
    ]), 'modifier_group_ids');

    $this->assertDatabaseCount('product_modifier_groups', 0);
});

test('modifier sync rejects nonexistent products even with an empty list', function () {
    $user = catalogUser();

    expect(fn () => app(SyncProductModifierGroups::class)->execute($user, new Product, []))
        ->toThrow(ModelNotFoundException::class);
});

test('modifier sync rolls back detached mappings if attachment fails', function () {
    $user = catalogUser();
    $product = Product::factory()->create();
    $original = ModifierGroup::factory()->create();
    $replacement = ModifierGroup::factory()->create();
    $product->modifierGroups()->attach($original);
    $failure = new RuntimeException('Attachment failed');
    DB::connection()->beforeExecuting(function (string $sql) use ($failure): void {
        if (str_starts_with($sql, 'insert into "product_modifier_groups"')) {
            throw $failure;
        }
    });

    expect(fn () => app(SyncProductModifierGroups::class)->execute($user, $product, [$replacement->id]))
        ->toThrow($failure);

    expect($product->modifierGroups()->sole()->is($original))->toBeTrue();
});
