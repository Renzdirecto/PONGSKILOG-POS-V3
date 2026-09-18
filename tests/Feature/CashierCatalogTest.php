<?php

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function catalogCashier(Branch $branch, string $role = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

test('authorized cashier roles receive the lean real catalog with default prices', function (string $role) {
    $branch = Branch::factory()->create();
    $category = Category::factory()->create(['name' => 'Silog']);
    $product = Product::factory()->for($category)->create(['name' => 'Tapsilog', 'default_price' => '99.25']);

    $this->actingAs(catalogCashier($branch, $role))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/show')
            ->where('branchContext.current.id', $branch->id)
            ->where('catalog', [
                'categories' => [['id' => $category->id, 'name' => 'Silog']],
                'products' => [[
                    'id' => $product->id,
                    'name' => 'Tapsilog',
                    'category_id' => $category->id,
                    'category_name' => 'Silog',
                    'effective_price' => '99.25',
                    'is_available' => true,
                    'image_url' => null,
                    'has_modifiers' => false,
                ]],
            ]));
})->with(['cashier', 'cashier_kitchen']);

test('cashier catalog requires authentication', function () {
    $this->get(route('workspaces.cashier'))->assertRedirectToRoute('login');
});

test('cashier catalog rejects inactive staff', function () {
    $user = catalogCashier(Branch::factory()->create());
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertRedirectToRoute('login');
    $this->assertGuest();
});

test('cashier catalog requires pos access permission', function () {
    $user = catalogCashier(Branch::factory()->create());
    $user->roles()->sole()->permissions()->detach(Permission::query()->where('name', 'pos.access')->sole());

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertForbidden();
});

test('kitchen only staff cannot read cashier catalog even with pos permission', function () {
    $user = catalogCashier(Branch::factory()->create(), 'kitchen_staff');
    $user->roles()->sole()->permissions()->syncWithoutDetaching(Permission::query()->where('name', 'pos.access')->sole());

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertForbidden();
});

test('cashier catalog rejects forged or revoked branch context', function (bool $revoked) {
    $branch = Branch::factory()->create();
    $user = catalogCashier($branch);
    if ($revoked) {
        $user->branches()->updateExistingPivot($branch, ['is_active' => false]);
    } else {
        $user->branches()->detach();
    }

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.cashier'))->assertRedirectToRoute('workspace')
        ->assertSessionMissing(ActiveBranchContext::SESSION_KEY);
})->with(['unassigned' => false, 'revoked' => true]);

test('cashier must select a branch when multiple assignments exist', function () {
    $user = catalogCashier(Branch::factory()->create());
    $user->branches()->attach(Branch::factory()->create(), ['is_active' => true]);

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertRedirectToRoute('workspace');
});

test('unavailable branches retain their safe workspace state without catalog data', function (BranchStatus $status) {
    $branch = Branch::factory()->create(['status' => $status]);
    Product::factory()->create();

    $this->actingAs(catalogCashier($branch))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('store.branchStatus', $status->value)
            ->where('catalog', ['categories' => [], 'products' => []]));
})->with([BranchStatus::Inactive, BranchStatus::TemporarilyClosed]);

test('catalog hides globally inactive items and sorts only populated active categories', function () {
    $branch = Branch::factory()->create();
    $last = Category::factory()->create(['name' => 'Drinks', 'sort_order' => 2]);
    $second = Category::factory()->create(['name' => 'Silog', 'sort_order' => 1]);
    $first = Category::factory()->create(['name' => 'Extras', 'sort_order' => 1]);
    Category::factory()->create(['name' => 'Empty']);
    Product::factory()->for(Category::factory()->create(['name' => 'Inactive only']))->create(['is_active' => false]);
    Product::factory()->for(Category::factory()->create(['is_active' => false]))->create();
    Product::factory()->for($last)->create(['name' => 'Water']);
    Product::factory()->for($second)->create(['name' => 'Tapsilog']);
    Product::factory()->for($first)->create(['name' => 'Rice']);
    Product::factory()->for($first)->create(['name' => 'Egg']);
    Product::factory()->for($first)->create(['is_active' => false]);

    $this->actingAs(catalogCashier($branch))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.categories', [
                ['id' => $first->id, 'name' => 'Extras'],
                ['id' => $second->id, 'name' => 'Silog'],
                ['id' => $last->id, 'name' => 'Drinks'],
            ])
            ->has('catalog.products', 4)
            ->where('catalog.products.0.name', 'Egg')
            ->where('catalog.products.1.name', 'Rice')
            ->where('catalog.products.2.name', 'Tapsilog')
            ->where('catalog.products.3.name', 'Water'));
});

test('branch switching isolates prices and unavailable overrides and ignores forged query scope', function () {
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $user = catalogCashier($main);
    $user->branches()->attach($qave, ['is_active' => true]);
    $product = Product::factory()->create(['default_price' => '95.00']);
    BranchProduct::factory()->for($product)->for($main)->create(['price_override' => '99.00', 'is_available' => false]);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $main->id])
        ->get(route('workspaces.cashier', ['branch_id' => $qave->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.effective_price', '99.00')
            ->where('catalog.products.0.is_available', false));

    $this->put(route('branch-context.update', $qave))->assertRedirectToRoute('workspace');
    $this->get(route('workspaces.cashier', ['branch_id' => $main->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('branchContext.current.id', $qave->id)
            ->where('catalog.products.0.effective_price', '95.00')
            ->where('catalog.products.0.is_available', true));
});

test('catalog prices preserve nullable zero and exact decimal overrides', function (?string $override, string $expected) {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create(['default_price' => '95.50']);
    BranchProduct::factory()->for($branch)->for($product)->create(['price_override' => $override]);

    $this->actingAs(catalogCashier($branch))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page->where('catalog.products.0.effective_price', $expected));
})->with([
    'inherit' => [null, '95.50'],
    'zero' => ['0.00', '0.00'],
    'decimal' => ['123.45', '123.45'],
    'maximum' => ['999999999999.99', '999999999999.99'],
]);

test('catalog signs only returned card variants and exposes no image internals', function () {
    $this->freezeTime();
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $directory = 'catalog/products/'.$product->id.'/'.Str::uuid();
    $product->update(['image_path' => $directory.'/detail.webp']);
    Product::factory()->create(['is_active' => false, 'image_path' => 'must-not-be-resolved']);
    Product::factory()->for(Category::factory()->create(['is_active' => false]))
        ->create(['image_path' => 'must-not-be-resolved']);
    $signedPaths = [];
    Storage::fake('s3')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration) use (&$signedPaths) {
        $signedPaths[] = $path;
        expect($expiration->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp());

        return 'https://assets.example.test/'.$path.'?signature=test';
    });

    $this->actingAs(catalogCashier($branch))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalog.products', 1)
            ->where('catalog.products.0.image_url', 'https://assets.example.test/'.$directory.'/card.webp?signature=test')
            ->missing('catalog.products.0.image_path')
            ->missing('catalog.products.0.source_url')
            ->missing('catalog.products.0.detail_url'));

    expect($signedPaths)->toBe([$directory.'/card.webp']);
});

test('only active assigned modifier groups set the summary without exposing groups or options', function (bool $active, bool $assigned, bool $expected) {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create(['is_active' => $active]);
    ModifierOption::factory()->for($group)->create(['is_active' => false]);
    if ($assigned) {
        $product->modifierGroups()->attach($group);
    }

    $this->actingAs(catalogCashier($branch))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.has_modifiers', $expected)
            ->missing('catalog.products.0.modifier_groups')
            ->missing('catalog.products.0.modifier_options'));
})->with([
    'active assigned' => [true, true, true],
    'inactive assigned' => [false, true, false],
    'active unassigned' => [true, false, false],
]);

test('closed and open store catalog reads never write operational data', function (bool $open) {
    $branch = Branch::factory()->create();
    $user = catalogCashier($branch);
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 4]);
    if ($open) {
        StoreSession::factory()->for($branch)->create();
    }
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->get(route('workspaces.cashier', ['browse' => 1, 'opening_cash_amount' => '50']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext.isOpen', $open)
            ->has('catalog.products', 1)
            ->where('catalog.products.0.is_available', true));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $writes = collect($queries)->filter(fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query['query']) === 1);
    expect($writes)->toBeEmpty();
    $this->assertDatabaseCount('store_sessions', $open ? 1 : 0);
    $this->assertDatabaseHas('branch_products', ['branch_id' => $branch->id, 'product_id' => $product->id, 'tracks_inventory' => true, 'low_stock_threshold' => 4]);
})->with(['closed' => false, 'open' => true]);

test('catalog query count stays bounded with products categories overrides and modifiers', function (int $count) {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $group = ModifierGroup::factory()->create();
    $products = Product::factory()->count($count)->create();
    foreach ($products as $product) {
        BranchProduct::factory()->for($branch)->for($product)->create();
        BranchProduct::factory()->for($other)->for($product)->create(['is_available' => false]);
        $product->modifierGroups()->attach($group);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();

    $catalog = app(BranchCatalog::class)->browse($branch);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($catalog['products'])->toHaveCount($count);
    expect(count($queries))->toBeLessThanOrEqual(3);
})->with([1, 30]);

test('an empty catalog returns empty lists', function () {
    $branch = Branch::factory()->create();
    Category::factory()->create();

    $this->actingAs(catalogCashier($branch))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page->where('catalog', ['categories' => [], 'products' => []]));
});
