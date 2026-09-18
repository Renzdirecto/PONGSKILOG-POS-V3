<?php

use App\Enums\ModifierSelectionType;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a category persists with UUID and active sorted defaults', function () {
    $category = Category::create(['name' => 'Silog'])->refresh();

    $this->assertModelExists($category);
    expect($category->id)->toBeUuid();
    expect($category->name)->toBe('Silog');
    expect($category->sort_order)->toBe(0);
    expect($category->is_active)->toBeTrue();
});

test('categories and products resolve only their own relationships', function () {
    $category = Category::factory()->create(['sort_order' => '3', 'is_active' => false]);
    $product = Product::factory()->for($category)->create();
    Product::factory()->create();

    expect($category->refresh()->sort_order)->toBe(3);
    expect($category->is_active)->toBeFalse();
    expect($category->products()->sole()->is($product))->toBeTrue();
    expect($product->category->is($category))->toBeTrue();
});

test('a product persists with UUID active default and nullable descriptive fields', function () {
    $category = Category::factory()->create();

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Pork Silog',
        'default_price' => '0.00',
    ])->refresh();

    $this->assertModelExists($product);
    expect($product->id)->toBeUuid();
    expect($product->is_active)->toBeTrue();
    expect($product->description)->toBeNull();
    expect($product->image_path)->toBeNull();
    expect($product->default_price)->toBe('0.00');
});

test('product prices round trip as exact decimal strings', function (string $price) {
    $product = Product::factory()->create(['default_price' => $price, 'is_active' => false])->refresh();

    expect($product->default_price)->toBe($price);
    expect($product->is_active)->toBeFalse();
})->with(['zero' => '0.00', 'one cent' => '0.01', 'large amount' => '123456789.12']);

test('branch product defaults preserve nullable overrides without tracking inventory', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();

    $branchProduct = BranchProduct::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
    ])->refresh();

    $this->assertModelExists($branchProduct);
    expect($branchProduct->id)->toBeUuid();
    expect($branchProduct->price_override)->toBeNull();
    expect($branchProduct->low_stock_threshold)->toBeNull();
    expect($branchProduct->is_available)->toBeTrue();
    expect($branchProduct->tracks_inventory)->toBeFalse();
});

test('branch product relationships keep separate branch records for the same global product', function () {
    $product = Product::factory()->create();
    $first = BranchProduct::factory()->for($product)->create();
    $second = BranchProduct::factory()->for($product)->create();
    BranchProduct::factory()->for($first->branch)->create();

    expect($first->branch_id)->not->toBe($second->branch_id);
    expect($first->product->is($product))->toBeTrue();
    expect($second->product->is($product))->toBeTrue();
    expect($first->branch->branchProducts()->where('product_id', $product->id)->sole()->is($first))->toBeTrue();
    expect($second->branch->branchProducts()->sole()->is($second))->toBeTrue();
    expect($product->branchProducts()->pluck('id')->all())->toEqualCanonicalizing([$first->id, $second->id]);
});

test('branch overrides round trip decimal strings and inventory configuration casts', function (string $price) {
    $branchProduct = BranchProduct::factory()->create([
        'price_override' => $price,
        'is_available' => false,
        'tracks_inventory' => true,
        'low_stock_threshold' => '0',
    ])->refresh();

    expect($branchProduct->price_override)->toBe($price);
    expect($branchProduct->is_available)->toBeFalse();
    expect($branchProduct->tracks_inventory)->toBeTrue();
    expect($branchProduct->low_stock_threshold)->toBe(0);
})->with(['zero override' => '0.00', 'one cent' => '0.01', 'large override' => '123456789.12']);

test('the database rejects duplicate branch product overrides', function () {
    $branchProduct = BranchProduct::factory()->create();
    $duplicate = $branchProduct->getAttributes();
    $duplicate['id'] = (string) Str::uuid();

    expect(fn () => DB::table('branch_products')->insert($duplicate))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('modifier groups persist with UUID selection enum and selection defaults', function () {
    $group = ModifierGroup::create(['name' => 'Egg', 'selection_type' => ModifierSelectionType::Single])->refresh();

    $this->assertModelExists($group);
    expect($group->id)->toBeUuid();
    expect($group->selection_type)->toBe(ModifierSelectionType::Single);
    expect($group->min_select)->toBe(0);
    expect($group->max_select)->toBe(1);
    expect($group->is_active)->toBeTrue();
});

test('modifier groups support multiple selection and integer bounds', function () {
    $group = ModifierGroup::factory()->create([
        'selection_type' => ModifierSelectionType::Multiple,
        'min_select' => '2',
        'max_select' => '2',
        'is_active' => false,
    ])->refresh();

    expect($group->selection_type)->toBe(ModifierSelectionType::Multiple);
    expect($group->min_select)->toBe(2);
    expect($group->max_select)->toBe(2);
    expect($group->is_active)->toBeFalse();
});

test('modifier options persist with UUID active and sort defaults', function () {
    $group = ModifierGroup::factory()->create();

    $option = ModifierOption::create([
        'modifier_group_id' => $group->id,
        'name' => 'Extra egg',
        'price_delta' => '0.00',
    ])->refresh();

    $this->assertModelExists($option);
    expect($option->id)->toBeUuid();
    expect($option->is_active)->toBeTrue();
    expect($option->sort_order)->toBe(0);
    expect($option->price_delta)->toBe('0.00');
});

test('modifier options belong only to their own group and preserve exact prices', function () {
    $group = ModifierGroup::factory()->create();
    $option = ModifierOption::factory()->for($group)->create([
        'price_delta' => '123456789.12',
        'is_active' => false,
        'sort_order' => '2',
    ])->refresh();
    ModifierOption::factory()->create();

    expect($option->modifierGroup->is($group))->toBeTrue();
    expect($group->options()->sole()->is($option))->toBeTrue();
    expect($option->price_delta)->toBe('123456789.12');
    expect($option->is_active)->toBeFalse();
    expect($option->sort_order)->toBe(2);
});

test('products and modifier groups resolve their many to many mappings in both directions', function () {
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $group = ModifierGroup::factory()->create();
    $otherGroup = ModifierGroup::factory()->create();

    $product->modifierGroups()->attach([$group->id, $otherGroup->id]);
    $group->products()->attach($otherProduct);

    expect($product->modifierGroups()->pluck('modifier_groups.id')->all())->toEqualCanonicalizing([$group->id, $otherGroup->id]);
    expect($group->products()->pluck('products.id')->all())->toEqualCanonicalizing([$product->id, $otherProduct->id]);
    expect($otherProduct->modifierGroups()->sole()->is($group))->toBeTrue();
    expect($otherGroup->products()->sole()->is($product))->toBeTrue();
});

test('the database rejects duplicate product modifier group mappings', function () {
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create();
    $product->modifierGroups()->attach($group);

    expect(fn () => $product->modifierGroups()->attach($group))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the database rejects negative prices and stock thresholds', function (string $modelClass, string $column, string|int $value) {
    $model = $modelClass::factory()->create();

    expect(fn () => DB::table($model->getTable())->where('id', $model->id)->update([$column => $value]))
        ->toThrow(QueryException::class, 'CHECK');
})->with([
    'default price' => [Product::class, 'default_price', '-0.01'],
    'branch override' => [BranchProduct::class, 'price_override', '-0.01'],
    'low stock threshold' => [BranchProduct::class, 'low_stock_threshold', -1],
    'modifier delta' => [ModifierOption::class, 'price_delta', '-0.01'],
]);

test('the database rejects invalid modifier selection bounds', function (int $min, int $max) {
    $group = ModifierGroup::factory()->create();

    expect(fn () => DB::table('modifier_groups')->where('id', $group->id)->update(['min_select' => $min, 'max_select' => $max]))
        ->toThrow(QueryException::class, 'CHECK');
})->with([
    'negative minimum' => [-1, 1],
    'negative maximum' => [0, -1],
    'maximum below minimum' => [2, 1],
]);

test('the database accepts zero selection bounds', function () {
    $group = ModifierGroup::factory()->create(['min_select' => 0, 'max_select' => 0])->refresh();

    expect($group->min_select)->toBe(0);
    expect($group->max_select)->toBe(0);
});

test('the database rejects unsupported modifier selection types', function () {
    $group = ModifierGroup::factory()->create();

    expect(fn () => DB::table('modifier_groups')->where('id', $group->id)->update(['selection_type' => 'discount']))
        ->toThrow(QueryException::class, 'CHECK');
});

test('the database requires catalog names', function (string $modelClass) {
    $model = $modelClass::factory()->create();

    expect(fn () => DB::table($model->getTable())->where('id', $model->id)->update(['name' => null]))
        ->toThrow(QueryException::class, 'NOT NULL');
})->with([Category::class, Product::class, ModifierGroup::class, ModifierOption::class]);

test('the database rejects missing catalog foreign references', function (string $modelClass, string $column) {
    $model = $modelClass::factory()->create();

    expect(fn () => DB::table($model->getTable())->where('id', $model->id)->update([
        $column => '00000000-0000-4000-8000-000000000001',
    ]))->toThrow(QueryException::class, 'FOREIGN KEY');
})->with([
    'product category' => [Product::class, 'category_id'],
    'override branch' => [BranchProduct::class, 'branch_id'],
    'override product' => [BranchProduct::class, 'product_id'],
    'option group' => [ModifierOption::class, 'modifier_group_id'],
]);

test('the database rejects missing product modifier mapping references', function (string $column) {
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create();
    $mapping = ['product_id' => $product->id, 'modifier_group_id' => $group->id];
    $mapping[$column] = '00000000-0000-4000-8000-000000000001';

    expect(fn () => DB::table('product_modifier_groups')->insert($mapping))
        ->toThrow(QueryException::class, 'FOREIGN KEY');
})->with(['product_id', 'modifier_group_id']);

test('deleting a referenced catalog parent cannot silently erase dependent records', function (string $modelClass, string $relation) {
    $model = $modelClass::factory()->create();

    expect(fn () => $model->{$relation}->delete())
        ->toThrow(QueryException::class, 'FOREIGN KEY');
})->with([
    'category with products' => [Product::class, 'category'],
    'branch with overrides' => [BranchProduct::class, 'branch'],
    'product with overrides' => [BranchProduct::class, 'product'],
    'group with options' => [ModifierOption::class, 'modifierGroup'],
]);

test('deleting a mapped product or modifier group requires explicit unlinking', function (string $parent) {
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create();
    $product->modifierGroups()->attach($group);
    $model = ['product' => $product, 'group' => $group][$parent];

    expect(fn () => $model->delete())
        ->toThrow(QueryException::class, 'FOREIGN KEY');
})->with(['product', 'group']);
