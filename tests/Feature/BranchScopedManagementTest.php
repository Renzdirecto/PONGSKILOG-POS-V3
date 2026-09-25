<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\ModifierGroup;
use App\Models\OperationPlan;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CustomRoles;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Scenario A of the Phase 18 Manual QA pass #2: a Branch Manager assigned to MAIN only. */
const BRANCH_MANAGER_PERMISSIONS = [
    'pos.access', 'transactions.view', 'reports.view', 'products.manage', 'inventory.manage',
    'operations.manage', 'staff.manage', 'settings.manage',
];

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    $this->main = Branch::factory()->create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $this->qave = Branch::factory()->create(['code' => 'QAVE', 'name' => 'Qave Branch']);
    $this->branchManagerRole = scopedRole('Branch Manager', 'branch', BRANCH_MANAGER_PERMISSIONS);
    $this->manager = scopedUser($this->branchManagerRole, [$this->main], ['name' => 'Maria Manager']);
});

/** @param list<string> $permissions */
function scopedRole(string $label, string $scope, array $permissions): Role
{
    test()->actingAs(test()->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => $label, 'scope' => $scope, 'permissions' => $permissions])
        ->assertSessionHasNoErrors();

    return Role::query()->whereNull('archived_at')->whereRaw('LOWER(label) = ?', [mb_strtolower((string) CustomRoles::normalizeLabel($label))])->sole();
}

/**
 * @param  list<Branch>  $branches
 * @param  array<string, mixed>  $attributes
 */
function scopedUser(Role|string $role, array $branches = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->attach($role instanceof Role ? $role : Role::query()->where('name', $role)->sole());
    foreach ($branches as $branch) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

function atBranch(User $user, Branch $branch): mixed
{
    return test()->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
}

test('a branch manager without a selected branch is sent to choose one instead of all branches', function () {
    $this->manager->branches()->attach($this->qave, ['is_active' => true]);

    foreach (['workspaces.owner', 'workspaces.transactions', 'products.index', 'inventory.index', 'operations.plans', 'branches.index'] as $route) {
        $this->actingAs($this->manager)->get(route($route))->assertRedirectToRoute('workspace');
    }
    $this->actingAs($this->manager)->get(route('workspace'))->assertRedirectToRoute('branches.select');
});

test('a branch manager without pos lands on its first branch management page', function () {
    $lead = scopedUser(scopedRole('Catalog Lead', 'branch', ['products.manage', 'staff.manage']), [$this->main]);

    $this->actingAs($lead)->get(route('workspace'))->assertRedirectToRoute('products.index');
});

test('products show only the selected branch assortment and configuration and no shared definition tools', function () {
    $product = Product::factory()->soldAt($this->main)->create();
    BranchProduct::factory()->for($this->qave)->for($product)->create(['price_override' => '99.00']);
    /** Sold only at QAVE: not part of the MAIN list. */
    BranchProduct::factory()->for($this->qave)->for(Product::factory()->create())->create();

    atBranch($this->manager, $this->main)->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/products')
            ->where('scope.mode', 'branch')
            ->where('scope.can_edit_definitions', false)
            ->where('scope.branch.code', 'MAIN')
            ->where('scope.copy_sources', [])
            ->where('modifierGroups', [])
            ->has('branchConfigurations', 1)
            ->where('branchConfigurations.0.code', 'MAIN')
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->has('products.data.0.branch_prices', 1)
            ->where('products.data.0.branch_prices.0.in_assortment', true)
            ->where('products.data.0.branch_prices.0.code', 'MAIN'));
});

test('a branch product manager cannot change any shared product definition', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create(['name' => 'Iced Tea']);
    $group = ModifierGroup::factory()->create();

    $this->actingAs($this->manager)->put(route('products.update', $product), [
        'name' => 'Renamed', 'category_id' => $category->id, 'default_price' => '10.00', 'is_active' => true, 'modifier_group_ids' => [],
    ])->assertForbidden();
    $this->actingAs($this->manager)->post(route('products.store'), ['name' => 'New'])->assertForbidden();
    $this->actingAs($this->manager)->get(route('categories.index'))->assertForbidden();
    $this->actingAs($this->manager)->put(route('categories.update', $category), ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->manager)->put(route('modifier-groups.update', $group), ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->manager)->delete(route('products.image.destroy', $product))->assertForbidden();

    expect($product->fresh()->name)->toBe('Iced Tea')
        ->and($category->fresh()->name)->not->toBe('Renamed');
});

test('a branch product manager configures its own branch and never another', function () {
    $product = Product::factory()->soldAt($this->main)->create();
    $notSold = Product::factory()->create();
    $configuration = ['price_override' => '55.00', 'is_available' => false, 'tracks_inventory' => false, 'low_stock_threshold' => null];

    $this->actingAs($this->manager)->put(route('products.branches.update', [$product, $this->main]), $configuration)->assertRedirect();
    $this->actingAs($this->manager)->put(route('products.branches.update', [$product, $this->qave]), $configuration)->assertForbidden();
    /** Branch settings edit an existing membership only; adding is its own explicit action. */
    $this->actingAs($this->manager)->put(route('products.branches.update', [$notSold, $this->main]), $configuration)->assertSessionHasErrors('product');

    expect(BranchProduct::query()->where('branch_id', $this->main->id)->sole()->only(['price_override', 'is_available']))
        ->toBe(['price_override' => '55.00', 'is_available' => false])
        ->and(BranchProduct::query()->where('branch_id', $this->qave->id)->exists())->toBeFalse();
});

test('adding existing products creates their branch membership once without duplicating anything', function () {
    $notSold = Product::factory()->create();
    $unavailable = Product::factory()->create();
    $row = BranchProduct::factory()->for($this->main)->for($unavailable)->create(['is_available' => false, 'price_override' => '42.00']);
    $productCount = Product::query()->count();
    $categoryCount = Category::query()->count();

    atBranch($this->manager, $this->main)
        ->post(route('products.branch-assortment.store'), ['product_ids' => [$notSold->id, $unavailable->id]])
        ->assertRedirect();
    atBranch($this->manager, $this->main)
        ->post(route('products.branch-assortment.store'), ['product_ids' => [$notSold->id]])
        ->assertRedirect();

    /** Unavailable is still a member: Add never touches it (availability is its own toggle). */
    expect($row->fresh()->is_available)->toBeFalse()
        ->and($row->fresh()->price_override)->toBe('42.00')
        ->and(BranchProduct::query()->where('branch_id', $this->main->id)->where('product_id', $notSold->id)->sole()->only(['price_override', 'is_available', 'tracks_inventory']))
        ->toBe(['price_override' => null, 'is_available' => true, 'tracks_inventory' => false])
        ->and(BranchProduct::query()->where('branch_id', $this->main->id)->count())->toBe(2)
        ->and(BranchProduct::query()->where('branch_id', $this->qave->id)->exists())->toBeFalse()
        ->and(Product::query()->count())->toBe($productCount)
        ->and(Category::query()->count())->toBe($categoryCount)
        ->and(AuditLog::query()->where('action', 'branch_products.added')->count())->toBe(1);
});

test('the add list offers only products outside the selected branch assortment', function () {
    $unavailable = Product::factory()->create(['name' => 'Halo-halo']);
    Product::factory()->create(['name' => 'Iced Tea']);
    BranchProduct::factory()->for($this->main)->for($unavailable)->create(['is_available' => false]);
    BranchProduct::factory()->for($this->qave)->for(Product::factory()->create(['name' => 'Qave Only']))->create();

    atBranch($this->manager, $this->main)->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('assortmentCandidates')
            ->reloadOnly('assortmentCandidates', fn (Assert $reload) => $reload
                ->has('assortmentCandidates', 2)
                ->where('assortmentCandidates.0.name', 'Iced Tea')
                ->where('assortmentCandidates.1.name', 'Qave Only')));
});

test('removing a product ends branch membership only; unavailable is a different action and history stays', function () {
    $product = Product::factory()->create();
    BranchProduct::factory()->for($this->main)->for($product)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($this->qave)->for($product)->create();
    BranchInventory::factory()->for($this->main)->for($product)->create(['on_hand' => 7]);
    $plan = OperationPlan::factory()->for($this->main)->create();
    OperationPlan::query()->whereKey($plan->id)->first()?->products()->attach($product->id, ['id' => (string) Str::uuid(), 'branch_id' => $this->main->id]);

    atBranch($this->manager, $this->main)->put(route('products.branches.update', [$product, $this->main]), [
        'price_override' => null, 'is_available' => false, 'tracks_inventory' => true, 'low_stock_threshold' => null,
    ])->assertRedirect();
    atBranch($this->manager, $this->main)->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.branch_prices.0.is_available', false));

    atBranch($this->manager, $this->main)->delete(route('products.branch-assortment.destroy'), ['product_ids' => [$product->id]])->assertRedirect();

    atBranch($this->manager, $this->main)->get(route('products.index'))->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    expect(BranchProduct::query()->where('branch_id', $this->main->id)->exists())->toBeFalse()
        ->and(BranchProduct::query()->where('branch_id', $this->qave->id)->where('product_id', $product->id)->exists())->toBeTrue()
        ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue()
        ->and(BranchInventory::query()->where('branch_id', $this->main->id)->sole()->on_hand)->toBe(7)
        ->and(DB::table('operation_plan_products')->where('branch_id', $this->main->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'branch_products.removed')->sole()->branch_id)->toBe($this->main->id);
    /** Its movement history stays readable by link. */
    $this->actingAs($this->manager)->get(route('inventory.movements.index', [$this->main, $product]))->assertOk();
});

test('copying from an authorized branch copies configuration only, skips existing rows and never stock', function () {
    $this->manager->branches()->attach($this->qave, ['is_active' => true]);
    [$new, $existing, $notAtSource] = Product::factory()->count(3)->create()->all();
    BranchProduct::factory()->for($this->qave)->for($new)->create(['price_override' => '75.00', 'is_available' => false, 'tracks_inventory' => true, 'low_stock_threshold' => 4]);
    BranchProduct::factory()->for($this->qave)->for($existing)->create(['price_override' => '80.00']);
    BranchInventory::factory()->for($this->qave)->for($new)->create(['on_hand' => 30]);
    $kept = BranchProduct::factory()->for($this->main)->for($existing)->create(['price_override' => '65.00']);
    $productCount = Product::query()->count();
    $payload = ['source_branch_id' => $this->qave->id, 'product_ids' => [$new->id, $existing->id, $notAtSource->id], 'overwrite' => false];

    /** Only Products QAVE sells can be copied from QAVE. */
    atBranch($this->manager, $this->main)->getJson(route('products.branch-assortment.copy.preview', ['source_branch_id' => $this->qave->id]))
        ->assertOk()
        ->assertJsonPath('destination.code', 'MAIN')
        ->assertJsonCount(2, 'products');
    atBranch($this->manager, $this->main)->post(route('products.branch-assortment.copy'), $payload)->assertRedirect();
    atBranch($this->manager, $this->main)->post(route('products.branch-assortment.copy'), $payload)->assertRedirect();

    $copied = BranchProduct::query()->where('branch_id', $this->main->id)->where('product_id', $new->id)->sole();
    expect($copied->only(['price_override', 'is_available', 'tracks_inventory', 'low_stock_threshold']))
        ->toBe(['price_override' => '75.00', 'is_available' => false, 'tracks_inventory' => true, 'low_stock_threshold' => 4])
        ->and($kept->fresh()->price_override)->toBe('65.00')
        ->and(BranchProduct::query()->where('branch_id', $this->main->id)->where('product_id', $notAtSource->id)->exists())->toBeFalse()
        ->and(BranchProduct::query()->where('branch_id', $this->main->id)->count())->toBe(2)
        ->and(BranchInventory::query()->where('branch_id', $this->main->id)->exists())->toBeFalse()
        ->and(InventoryMovement::query()->where('branch_id', $this->main->id)->exists())->toBeFalse()
        ->and(Product::query()->count())->toBe($productCount);

    atBranch($this->manager, $this->main)->post(route('products.branch-assortment.copy'), [...$payload, 'overwrite' => true])->assertRedirect();
    expect($kept->fresh()->price_override)->toBe('80.00');
});

test('copy never reads or writes a branch outside the manager scope', function () {
    $product = Product::factory()->create();
    BranchProduct::factory()->for($this->qave)->for($product)->create(['price_override' => '75.00']);

    atBranch($this->manager, $this->main)->getJson(route('products.branch-assortment.copy.preview', ['source_branch_id' => $this->qave->id]))
        ->assertForbidden();
    atBranch($this->manager, $this->main)->post(route('products.branch-assortment.copy'), [
        'source_branch_id' => $this->qave->id, 'product_ids' => [$product->id], 'overwrite' => true,
    ])->assertForbidden();
    /** A forged QAVE selection falls back to the manager's own MAIN: QAVE is never removed from or written. */
    atBranch($this->manager, $this->qave)->delete(route('products.branch-assortment.destroy'), ['product_ids' => [$product->id]])->assertRedirect();

    expect(BranchProduct::query()->where('branch_id', $this->main->id)->exists())->toBeFalse()
        ->and(BranchProduct::query()->where('branch_id', $this->qave->id)->sole()->only(['is_available', 'price_override']))->toBe(['is_available' => true, 'price_override' => '75.00'])
        ->and(AuditLog::query()->whereIn('action', ['branch_products.added', 'branch_products.copied', 'branch_products.removed'])->exists())->toBeFalse();
});

test('a business-wide product manager may copy from every other active branch', function () {
    $areaManager = scopedUser(scopedRole('Area Manager', 'business', ['products.manage']));

    atBranch($areaManager, $this->main)->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.mode', 'business')
            ->where('scope.can_edit_definitions', true)
            ->where('scope.copy_sources', [['id' => $this->qave->id, 'name' => 'Qave Branch', 'code' => 'QAVE']]));
});

test('inventory stays on the assigned branch and a foreign branch cannot be read or adjusted', function () {
    $product = Product::factory()->create();
    BranchProduct::factory()->for($this->qave)->for($product)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($this->qave)->for($product)->create(['on_hand' => 10]);

    atBranch($this->manager, $this->main)->get(route('inventory.index', ['branch_id' => $this->qave->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedBranch.code', 'MAIN')
            ->has('branches', 1)
            ->where('branches.0.code', 'MAIN'));
    $this->actingAs($this->manager)->get(route('inventory.movements.index', [$this->qave, $product]))->assertForbidden();
    $this->actingAs($this->manager)->post(route('inventory.adjustments.store', [$this->qave, $product]), ['quantity_delta' => 5, 'reason' => 'Forged'])
        ->assertForbidden();

    expect(BranchInventory::query()->where('branch_id', $this->qave->id)->sole()->on_hand)->toBe(10);
});

test('a branch operations manager configures only its own branch setup and never reaches another branch', function () {
    $ingredient = Ingredient::factory()->for($this->main)->create(['name' => 'Calamansi']);
    $qaveIngredient = Ingredient::factory()->for($this->qave)->create(['name' => 'Calamansi']);
    $qavePlan = OperationPlan::factory()->for($this->qave)->create(['name' => 'Qave drinks']);

    atBranch($this->manager, $this->main)->get(route('operations.ingredients'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('operations.branch.code', 'MAIN')
            ->where('operations.can_configure', true)
            ->where('operations.copy_sources', [])
            ->where('ingredients', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$ingredient->id]));
    atBranch($this->manager, $this->main)->post(route('operations.ingredients.adjust', $ingredient), [
        'mode' => 'count', 'quantity' => '12', 'reason' => 'Opening count', 'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect()->assertSessionHasNoErrors();
    atBranch($this->manager, $this->main)->post(route('operations.plans.store'), ['name' => 'Main drinks', 'icon' => 'box', 'product_ids' => []])
        ->assertRedirect()->assertSessionHasNoErrors();

    atBranch($this->manager, $this->main)->post(route('operations.ingredients.adjust', $qaveIngredient), [
        'mode' => 'count', 'quantity' => '99', 'reason' => 'Opening count', 'idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();
    atBranch($this->manager, $this->main)->put(route('operations.ingredients.update', $qaveIngredient), ['name' => 'Renamed'])->assertNotFound();
    atBranch($this->manager, $this->main)->post(route('operations.plans.archive', $qavePlan))->assertNotFound();
    atBranch($this->manager, $this->main)->getJson(route('operations.setup-copy.preview', ['source_branch_id' => $this->qave->id, 'sections' => ['ingredients'], 'replace' => 0]))
        ->assertForbidden();

    expect(BranchIngredientStock::query()->where('ingredient_id', $ingredient->id)->sole()->branch_id)->toBe($this->main->id)
        ->and(BranchIngredientStock::query()->where('ingredient_id', $qaveIngredient->id)->exists())->toBeFalse()
        ->and($qaveIngredient->fresh()->name)->toBe('Calamansi')
        ->and($qavePlan->fresh()->archived_at)->toBeNull()
        ->and(OperationPlan::query()->where('name', 'Main drinks')->sole()->branch_id)->toBe($this->main->id);
});

test('a branch staff manager lists only other accounts of its own branches', function () {
    $mainCashier = scopedUser('cashier', [$this->main], ['name' => 'Main Cashier']);
    scopedUser('cashier', [$this->qave], ['name' => 'Qave Cashier']);
    $shared = scopedUser('cashier', [$this->main, $this->qave], ['name' => 'Shared Cashier']);
    scopedUser('owner', [], ['name' => 'The Owner']);
    scopedUser(scopedRole('Area Manager', 'business', ['reports.view']), [], ['name' => 'Area Lead']);

    atBranch($this->manager, $this->main)->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('branchScoped', true)
            ->where('staff.data', fn ($rows): bool => collect($rows)->pluck('name')->sort()->values()->all() === ['Main Cashier', 'Shared Cashier'])
            ->where('staff.data', fn ($rows): bool => collect($rows)->firstWhere('id', $shared->id)['other_branch_count'] === 1
                && collect(collect($rows)->firstWhere('id', $shared->id)['branches'])->pluck('code')->all() === ['MAIN'])
            ->where('branches', fn ($branches): bool => collect($branches)->pluck('code')->all() === ['MAIN'])
            ->where('roles', fn ($roles): bool => ! collect($roles)->pluck('name')->intersect(['owner', 'super_admin'])->count()));
    $this->actingAs($this->manager)->put(route('staff.update', scopedUser('cashier', [$this->qave])), [
        'name' => 'Hijack', 'email' => 'hijack@example.test', 'role' => 'cashier', 'branch_ids' => [$this->main->id], 'is_active' => true,
    ])->assertForbidden();
    expect($mainCashier->fresh()->name)->toBe('Main Cashier');
});

test('a branch staff manager creates accounts only for its branches and within its own access', function (array $payload, string $field) {
    /** Kitchen access is outside the manager's own access, so it may not hand out this role. */
    $powerful = scopedRole('Kitchen Lead', 'branch', ['kitchen.access']);
    $input = [
        'employee_id' => '09252699', 'name' => 'New Hire', 'email' => 'new.hire@example.test',
        'password' => 'Temporary-Pass-42', 'password_confirmation' => 'Temporary-Pass-42',
        'role' => 'cashier', 'branch_ids' => [$this->main->id], 'is_active' => true, ...$payload,
    ];
    $input['role'] = $input['role'] === 'powerful' ? $powerful->name : $input['role'];
    $input['branch_ids'] = array_map(fn (string $id): string => $id === 'qave' ? $this->qave->id : $id, $input['branch_ids']);

    atBranch($this->manager, $this->main)->post(route('staff.store'), $input)->assertInvalid([$field]);
    expect(User::query()->where('email', 'new.hire@example.test')->exists())->toBeFalse();
})->with([
    'a foreign branch' => [['branch_ids' => ['qave']], 'branch_ids.0'],
    'the owner role' => [['role' => 'owner', 'branch_ids' => []], 'role'],
    'a role with more access than the manager' => [['role' => 'powerful'], 'role'],
]);

test('a branch staff manager creates a cashier at its own branch', function () {
    atBranch($this->manager, $this->main)->post(route('staff.store'), [
        'employee_id' => '09252698', 'name' => 'New Hire', 'email' => 'new.hire@example.test',
        'password' => 'Temporary-Pass-42', 'password_confirmation' => 'Temporary-Pass-42',
        'role' => 'cashier', 'branch_ids' => [$this->main->id], 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'new.hire@example.test')->sole()->branches()->pluck('code')->all())->toBe(['MAIN']);
});

test('a shared account keeps its hidden branch and only its access to the manager branches changes', function () {
    $shared = scopedUser('cashier', [$this->main, $this->qave], ['name' => 'Shared Cashier', 'position' => 'Barista']);
    $base = ['name' => 'Shared Cashier', 'email' => $shared->email, 'role' => 'cashier', 'is_active' => true];

    atBranch($this->manager, $this->main)->put(route('staff.update', $shared), [...$base, 'position' => 'Head Barista', 'branch_ids' => [$this->main->id]])
        ->assertInvalid(['branch_ids']);
    atBranch($this->manager, $this->main)->put(route('staff.update', $shared), [...$base, 'branch_ids' => []])
        ->assertSessionHasNoErrors();

    expect($shared->fresh()->position)->toBe('Barista')
        ->and($shared->fresh()->branches()->wherePivot('is_active', true)->pluck('code')->all())->toBe(['QAVE']);
});

test('branch settings show and change only the safe settings of the assigned branch', function () {
    atBranch($this->manager, $this->main)->get(route('branches.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('branches', 1)
            ->where('branches.0.code', 'MAIN')
            ->where('scope.mode', 'branch')
            ->where('scope.can_create', false)
            ->where('scope.can_edit_identity', false));
    $identity = ['code' => 'MAIN', 'name' => 'Main Branch', 'status' => 'active'];

    atBranch($this->manager, $this->main)->put(route('branches.update', $this->main), [...$identity, 'contact' => '0917 000 0000', 'address' => null])
        ->assertSessionHasNoErrors();
    atBranch($this->manager, $this->main)->put(route('branches.update', $this->main), [...$identity, 'name' => 'Renamed', 'contact' => null, 'address' => null])
        ->assertInvalid(['name' => 'Only a business-wide Settings role can change a Branch code, name or status.']);
    atBranch($this->manager, $this->main)->putJson(route('branches.qr-settings.update', $this->main), ['receipt_footer' => 'Salamat!'])->assertOk();
    atBranch($this->manager, $this->main)->putJson(route('branches.qr-settings.update', $this->qave), ['receipt_footer' => 'Forged'])->assertForbidden();
    atBranch($this->manager, $this->main)->put(route('branches.update', $this->qave), ['code' => 'QAVE', 'name' => 'Qave Branch', 'status' => 'active'])->assertForbidden();
    atBranch($this->manager, $this->main)->post(route('branches.store'), ['code' => 'NEW', 'name' => 'New', 'status' => 'active'])->assertForbidden();

    expect($this->main->fresh()->only(['name', 'contact', 'receipt_footer']))->toBe(['name' => 'Main Branch', 'contact' => '0917 000 0000', 'receipt_footer' => 'Salamat!'])
        ->and($this->qave->fresh()->receipt_footer)->toBeNull()
        ->and(Branch::query()->count())->toBe(2);
});

test('a business-wide area manager keeps business settings but never control', function () {
    $areaManager = scopedUser(scopedRole('Area Manager', 'business', BRANCH_MANAGER_PERMISSIONS));

    $this->actingAs($areaManager)->get(route('branches.index'))
        ->assertInertia(fn (Assert $page) => $page->has('branches', 2)->where('scope.mode', 'business')->where('scope.can_create', true));
    foreach (['workspaces.audit-trail', 'workspaces.void-orders', 'super-admin.access-control', 'workspaces.super-admin'] as $route) {
        $this->actingAs($areaManager)->get(route($route))->assertForbidden();
    }
});
