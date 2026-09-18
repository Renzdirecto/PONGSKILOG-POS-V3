<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function catalogWebManager(string $role = 'owner'): User
{
    test()->seed(RbacSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function catalogProductInput(Category $category, array $overrides = []): array
{
    return array_replace(['name' => 'Tapsilog', 'category_id' => $category->id, 'description' => 'Rice and egg', 'default_price' => '95.00', 'is_active' => true, 'modifier_group_ids' => []], $overrides);
}

test('managers can create and edit products with modifier assignments', function (string $role) {
    $user = catalogWebManager($role);
    $category = Category::factory()->create();
    $group = ModifierGroup::factory()->create();
    $other = Product::factory()->create();
    $other->modifierGroups()->attach($group);
    $this->actingAs($user)->post(route('products.store'), catalogProductInput($category, ['modifier_group_ids' => [$group->id], 'image_path' => 'untrusted']))
        ->assertRedirectToRoute('products.index')->assertSessionHasNoErrors();
    $product = Product::query()->where('name', 'Tapsilog')->sole();
    expect($product->default_price)->toBe('95.00')->and($product->image_path)->toBeNull();
    $this->assertDatabaseHas('product_modifier_groups', ['product_id' => $product->id, 'modifier_group_id' => $group->id]);

    $this->from(route('products.index'))->put(route('products.update', $product), catalogProductInput($category, ['name' => 'Updated meal', 'default_price' => '99.00', 'is_active' => false]))
        ->assertRedirectToRoute('products.index')->assertSessionHasNoErrors();
    expect($product->refresh()->name)->toBe('Updated meal')->and($product->default_price)->toBe('99.00')->and($product->is_active)->toBeFalse();
    $this->assertDatabaseMissing('product_modifier_groups', ['product_id' => $product->id]);
    $this->assertDatabaseHas('product_modifier_groups', ['product_id' => $other->id, 'modifier_group_id' => $group->id]);
})->with(['owner', 'super_admin']);

test('invalid modifier assignments roll back the entire product save', function (bool $creating) {
    $user = catalogWebManager();
    $product = Product::factory()->create(['name' => 'Original', 'default_price' => '95.00']);
    $group = ModifierGroup::factory()->create();
    $product->modifierGroups()->attach($group);
    $input = catalogProductInput($product->category, ['name' => 'Changed', 'modifier_group_ids' => [Str::uuid()->toString()]]);

    $this->actingAs($user)->{ $creating ? 'post' : 'put' }(route($creating ? 'products.store' : 'products.update', $creating ? [] : $product), $input)
        ->assertSessionHasErrors('modifier_group_ids.0');

    $this->assertDatabaseCount('products', 1);
    expect($product->refresh()->name)->toBe('Original');
    $this->assertDatabaseHas('product_modifier_groups', ['product_id' => $product->id, 'modifier_group_id' => $group->id]);
})->with([true, false]);

test('product requests reject malformed core and assignment input without writes', function (array $invalid, string $field) {
    $user = catalogWebManager();
    $category = Category::factory()->create();

    $this->actingAs($user)->post(route('products.store'), catalogProductInput($category, $invalid))->assertSessionHasErrors($field);

    $this->assertDatabaseCount('products', 0);
})->with([
    'money' => [['default_price' => '-1.00'], 'default_price'],
    'category' => [['category_id' => 'invalid'], 'category_id'],
    'group list' => [['modifier_group_ids' => 'invalid'], 'modifier_group_ids'],
    'status' => [['is_active' => 'invalid'], 'is_active'],
]);

test('management listing exposes exact branch prices and safe image urls', function () {
    $user = catalogWebManager();
    $main = Branch::factory()->create(['code' => 'MAIN']);
    Branch::factory()->create(['code' => 'QAVE']);
    $product = Product::factory()->create(['default_price' => '95.00']);
    $product->update(['image_path' => 'catalog/products/'.$product->id.'/'.Str::uuid().'/detail.webp']);
    Storage::fake('s3')->buildTemporaryUrlsUsing(fn ($path) => 'https://assets.example.test/'.$path);
    BranchProduct::factory()->for($product)->for($main)->create(['price_override' => '99.00', 'is_available' => false]);

    $this->actingAs($user)->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->component('catalog/products')->has('products.data', 1)
        ->where('products.data.0.default_price', '95.00')
        ->where('products.data.0.branch_prices.0.code', 'MAIN')
        ->where('products.data.0.branch_prices.0.effective_price', '99.00')
        ->where('products.data.0.branch_prices.0.effective_available', false)
        ->where('products.data.0.branch_prices.1.code', 'QAVE')
        ->where('products.data.0.branch_prices.1.price_override', null)
        ->where('products.data.0.branch_prices.1.effective_price', '95.00')
        ->where('products.data.0.image_url', 'https://assets.example.test/'.dirname($product->image_path).'/card.webp')
        ->missing('products.data.0.image_path'));
});

test('product search category status and pagination constrain the catalog', function () {
    $user = catalogWebManager();
    $category = Category::factory()->create();
    $target = Product::factory()->for($category)->create(['name' => 'Tapsilog', 'is_active' => false]);
    Product::factory()->for($category)->create(['name' => 'Tapsilog active']);
    Product::factory()->count(24)->create();

    $this->actingAs($user)->get(route('products.index', ['search' => 'tapsilog', 'category' => $category->id, 'status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $target->id));
    $this->get(route('products.index'))->assertInertia(fn (Assert $page) => $page->has('products.data', 24)->where('products.total', 26)->where('products.last_page', 2));
    $this->get(route('products.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('products.data', 2));
});

test('catalog listing query count stays bounded as products and branches grow', function () {
    $user = catalogWebManager();
    Product::factory()->create();
    Branch::factory()->create();
    $this->actingAs($user);
    DB::enableQueryLog();
    $this->get(route('products.index'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    Product::factory()->count(10)->create();
    Branch::factory()->count(3)->create();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('products.index'))->assertOk();

    expect(count(DB::getQueryLog()))->toBe($count);
    DB::disableQueryLog();
});

test('branch prices can be overridden and restored without changing other branches or inventory settings', function () {
    $user = catalogWebManager();
    $product = Product::factory()->create(['default_price' => '95.00']);
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $override = BranchProduct::factory()->for($product)->for($main)->create(['tracks_inventory' => true, 'low_stock_threshold' => 7]);
    $other = BranchProduct::factory()->for($product)->for($qave)->create(['price_override' => null]);
    $otherAttributes = $other->fresh()->getAttributes();
    $this->actingAs($user)->from(route('products.index'))->put(route('products.branches.update', [$product, $main]), ['price_override' => '99.00', 'is_available' => false, 'tracks_inventory' => false, 'low_stock_threshold' => 0])
        ->assertRedirectToRoute('products.index')->assertSessionHasNoErrors();
    expect($override->refresh()->price_override)->toBe('99.00')->and($override->is_available)->toBeFalse()->and($override->tracks_inventory)->toBeTrue()->and($override->low_stock_threshold)->toBe(7);

    $this->put(route('products.branches.update', [$product, $main]), ['price_override' => null, 'is_available' => true])->assertSessionHasNoErrors();
    expect($override->refresh()->price_override)->toBeNull()->and($override->is_available)->toBeTrue();
    expect($other->refresh()->getAttributes())->toBe($otherAttributes);
    $this->assertDatabaseCount('branch_products', 2);
});

test('zero override prices are preserved and invalid prices do not replace them', function () {
    $product = Product::factory()->create();
    $branch = Branch::factory()->create();
    $this->actingAs(catalogWebManager())->put(route('products.branches.update', [$product, $branch]), ['price_override' => '0.00', 'is_available' => true])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('branch_products', ['product_id' => $product->id, 'branch_id' => $branch->id, 'price_override' => 0]);

    $this->put(route('products.branches.update', [$product, $branch]), ['price_override' => '-1.00', 'is_available' => false])->assertSessionHasErrors('price_override');
    expect(BranchProduct::query()->sole()->price_override)->toBe('0.00')->and(BranchProduct::query()->sole()->is_available)->toBeTrue();
});

test('category management creates edits and deactivates categories', function () {
    $this->actingAs(catalogWebManager())->post(route('categories.store'), ['name' => 'Meals', 'sort_order' => 2, 'is_active' => true])->assertRedirectToRoute('categories.index')->assertSessionHasNoErrors();
    $category = Category::query()->sole();
    $this->put(route('categories.update', $category), ['name' => 'Rice meals', 'sort_order' => 3, 'is_active' => false])->assertSessionHasNoErrors();
    expect($category->refresh()->name)->toBe('Rice meals')->and($category->is_active)->toBeFalse()->and($category->sort_order)->toBe(3);
    $this->get(route('categories.index'))->assertInertia(fn (Assert $page) => $page->component('catalog/categories')->has('categories', 1)->where('categories.0.products_count', 0));
    $this->post(route('categories.store'), ['name' => '', 'sort_order' => -1, 'is_active' => true])->assertSessionHasErrors(['name', 'sort_order']);
    $this->assertDatabaseCount('categories', 1);
});

test('modifier management creates edits and deactivates groups and options', function () {
    $this->actingAs(catalogWebManager())->post(route('modifier-groups.store'), ['name' => 'Extras', 'selection_type' => 'multiple', 'min_select' => 0, 'max_select' => 3, 'is_active' => true])->assertRedirectToRoute('modifier-groups.index')->assertSessionHasNoErrors();
    $group = ModifierGroup::query()->sole();
    $this->post(route('modifier-options.store'), ['modifier_group_id' => $group->id, 'name' => 'Egg', 'price_delta' => '15.00', 'sort_order' => 1, 'is_active' => true])->assertRedirectToRoute('modifier-groups.index')->assertSessionHasNoErrors();
    $option = ModifierOption::query()->sole();
    $this->put(route('modifier-options.update', $option), ['modifier_group_id' => $group->id, 'name' => 'Extra egg', 'price_delta' => '20.00', 'sort_order' => 2, 'is_active' => false])->assertSessionHasNoErrors();
    $this->put(route('modifier-groups.update', $group), ['name' => 'Add-ons', 'selection_type' => 'single', 'min_select' => 0, 'max_select' => 1, 'is_active' => false])->assertSessionHasNoErrors();
    expect($option->refresh()->price_delta)->toBe('20.00')->and($option->name)->toBe('Extra egg')->and($option->is_active)->toBeFalse();
    expect($group->refresh()->selection_type->value)->toBe('single')->and($group->is_active)->toBeFalse();
    $this->get(route('modifier-groups.index'))->assertInertia(fn (Assert $page) => $page->component('catalog/modifiers')->where('groups.0.name', 'Add-ons')->where('groups.0.options.0.name', 'Extra egg'));
    $this->post(route('modifier-groups.store'), ['name' => 'Bad', 'selection_type' => 'multiple', 'min_select' => 3, 'max_select' => 1, 'is_active' => true])->assertSessionHasErrors('max_select');
    $this->post(route('modifier-options.store'), ['modifier_group_id' => Str::uuid()->toString(), 'name' => 'Bad', 'price_delta' => '-1.00', 'sort_order' => 0, 'is_active' => true])->assertSessionHasErrors(['modifier_group_id', 'price_delta']);
    $this->assertDatabaseCount('modifier_groups', 1);
    $this->assertDatabaseCount('modifier_options', 1);
});

test('image endpoints upload replace and remove optimized product images', function () {
    $disk = Storage::fake('s3');
    $product = Product::factory()->create();
    $this->actingAs(catalogWebManager())->from(route('products.index'))->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->image('meal.jpg')])->assertRedirectToRoute('products.index')->assertSessionHasNoErrors();
    $oldPath = $product->refresh()->image_path;
    $disk->assertExists([$oldPath, dirname($oldPath).'/card.webp']);

    $this->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->image('replacement.png')])->assertSessionHasNoErrors();
    $newPath = $product->refresh()->image_path;
    expect($newPath)->not->toBe($oldPath);
    $disk->assertDirectoryEmpty(dirname($oldPath));
    $disk->assertExists($newPath);

    $this->delete(route('products.image.destroy', $product))->assertRedirectToRoute('products.index')->assertSessionHasNoErrors();
    expect($product->refresh()->image_path)->toBeNull();
    $disk->assertDirectoryEmpty(dirname($newPath));
});

test('invalid image uploads return field errors without saving an asset', function () {
    $disk = Storage::fake('s3');
    $product = Product::factory()->create();
    $this->actingAs(catalogWebManager())->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->createWithContent('photo.jpg', 'not an image')])->assertSessionHasErrors('image');
    expect($product->refresh()->image_path)->toBeNull();
    $disk->assertDirectoryEmpty('catalog');
});

test('image storage failures return a recoverable error and preserve the current image', function () {
    $product = Product::factory()->create();
    $oldPath = 'catalog/products/'.$product->id.'/'.Str::uuid().'/detail.webp';
    $product->update(['image_path' => $oldPath]);
    Storage::shouldReceive('disk')->with('s3')->andThrow(new RuntimeException('Storage unavailable'));

    $this->actingAs(catalogWebManager())->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->image('photo.jpg')])
        ->assertSessionHasErrors(['image' => 'The image could not be saved. Please try again.']);

    expect($product->refresh()->image_path)->toBe($oldPath);
});

test('product and category inactivity override branch availability in management', function (bool $categoryActive, bool $productActive) {
    $category = Category::factory()->create(['is_active' => $categoryActive]);
    Product::factory()->for($category)->create(['is_active' => $productActive]);
    Branch::factory()->create();

    $this->actingAs(catalogWebManager())->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.branch_prices.0.is_available', true)
        ->where('products.data.0.branch_prices.0.effective_available', false));
})->with([[false, true], [true, false]]);

test('missing catalog records return not found without creating branch overrides', function () {
    $this->actingAs(catalogWebManager());
    $product = Product::factory()->create();
    $branch = Branch::factory()->create();
    $missing = Str::uuid()->toString();

    $this->put(route('products.update', $missing))->assertNotFound();
    $this->put(route('categories.update', $missing))->assertNotFound();
    $this->put(route('modifier-groups.update', $missing))->assertNotFound();
    $this->put(route('modifier-options.update', $missing))->assertNotFound();
    $this->post(route('products.image.store', $missing))->assertNotFound();
    $this->delete(route('products.image.destroy', $missing))->assertNotFound();
    $this->put(route('products.branches.update', [$product, $missing]))->assertNotFound();
    $this->put(route('products.branches.update', [$missing, $branch]))->assertNotFound();

    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('branch_products', 0);
});

test('catalog endpoints require active authentication and catalog permission', function (string $access) {
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create();
    $option = ModifierOption::factory()->for($group)->create();
    $branch = Branch::factory()->create();
    if ($access !== 'guest') {
        $user = catalogWebManager($access === 'staff' ? 'cashier' : 'owner');
        if ($access === 'inactive') {
            $user->forceFill(['is_active' => false])->save();
        }
        if ($access === 'revoked') {
            $user->roles()->firstOrFail()->permissions()->detach();
        }
        $this->actingAs($user);
    }
    $routes = [
        ['get', 'products.index', []], ['post', 'products.store', []], ['put', 'products.update', $product],
        ['get', 'categories.index', []], ['post', 'categories.store', []], ['put', 'categories.update', $product->category],
        ['get', 'modifier-groups.index', []], ['post', 'modifier-groups.store', []], ['put', 'modifier-groups.update', $group],
        ['post', 'modifier-options.store', []], ['put', 'modifier-options.update', $option],
        ['post', 'products.image.store', $product], ['delete', 'products.image.destroy', $product],
        ['put', 'products.branches.update', [$product, $branch]],
    ];
    foreach ($routes as [$method, $name, $parameters]) {
        $response = $this->{$method}(route($name, $parameters));
        if (in_array($access, ['guest', 'inactive'])) {
            $response->assertRedirectToRoute('login');
        } else {
            $response->assertForbidden();
        }
    }
    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('branch_products', 0);
    $this->assertDatabaseCount('modifier_groups', 1);
    $this->assertDatabaseCount('modifier_options', 1);
})->with(['guest', 'inactive', 'staff', 'revoked']);
