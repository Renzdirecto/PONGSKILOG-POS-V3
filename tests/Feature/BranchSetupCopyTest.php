<?php

use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Events\CustomerCatalogChanged;
use App\Events\IngredientStockChanged;
use App\Events\ReportsChanged;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\CustomerQrSession;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\OperationPlan;
use App\Models\OperationPlanProduct;
use App\Models\Order;
use App\Models\OrderRecipeSnapshot;
use App\Models\PamamalengkePurchase;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\CustomRoles;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\OperationsScenario;

/**
 * Manual QA pass #2.1: a new TEST Branch starts clean, receives MAIN's Products and Operations setup through a
 * configuration-only copy, and is independent from MAIN afterwards.
 */
beforeEach(function () {
    $this->ops = OperationsScenario::create()->withAddOns();
    $this->test = Branch::factory()->create(['code' => 'TEST', 'name' => 'Test Branch']);
});

function onBranch(object $test, User $user, Branch $branch): mixed
{
    return $test->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
}

/** @param array<string, mixed> $overrides */
function copyFromMain(object $test, array $overrides = []): mixed
{
    return onBranch($test, $test->ops->owner, $test->test)->post(route('products.branch-assortment.copy'), [
        'source_branch_id' => $test->ops->branch->id,
        'product_ids' => [$test->ops->lemonYakult->id, $test->ops->tapsilog->id],
        'overwrite' => false,
        'copy_operations' => true,
        ...$overrides,
    ]);
}

/** @return array<string, string> ingredient name => quantity of one Branch recipe */
function recipeLines(Branch $branch, Product $product, ?ModifierOption $size): array
{
    return RecipeLine::query()->whereIn('recipe_id', Recipe::query()->where('branch_id', $branch->id)->where('product_id', $product->id)
        ->where('size_key', Recipe::sizeKey($size?->id))->select('id'))
        ->join('ingredients', 'ingredients.id', '=', 'recipe_lines.ingredient_id')
        ->orderBy('ingredients.name')->pluck('recipe_lines.quantity', 'ingredients.name')->map(fn ($quantity): string => (string) (float) $quantity)->all();
}

test('a new branch starts with no products, no operations setup and empty POS and QR catalogs', function () {
    onBranch($this, $this->ops->owner, $this->test)->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 0)->where('scope.branch.code', 'TEST'));
    onBranch($this, $this->ops->owner, $this->test)->get(route('operations.plans'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('operations.branch.code', 'TEST')
            ->where('operations.setup', ['plans' => 0, 'ingredients' => 0, 'recipes' => 0, 'products' => 0])
            ->where('operations.plans', [])
            ->where('operations.can_configure', true)
            ->where('operations.copy_sources', [['id' => $this->ops->branch->id, 'name' => 'Main', 'code' => 'MAIN']]));
    onBranch($this, $this->ops->owner, $this->test)->get(route('operations.ingredients'))
        ->assertInertia(fn (Assert $page) => $page->where('ingredients', []));

    expect(app(BranchCatalog::class)->browse($this->test))->toBe(['categories' => [], 'products' => []])
        ->and(BranchIngredientStock::query()->where('branch_id', $this->test->id)->exists())->toBeFalse();
});

test('copying products with their operations setup clones configuration once and never stock or history', function () {
    $counts = fn (): array => [Product::query()->count(), Category::query()->count(), ModifierGroup::query()->count(), ModifierOption::query()->count()];
    $before = $counts();
    $mainRecipes = Recipe::query()->where('branch_id', $this->ops->branch->id)->count();

    copyFromMain($this)->assertRedirect()->assertSessionHasNoErrors();

    $test = $this->test->id;
    $plans = OperationPlan::query()->where('branch_id', $test)->orderBy('name')->get();
    expect($counts())->toBe($before)
        ->and(BranchProduct::query()->where('branch_id', $test)->pluck('product_id')->sort()->values()->all())
        ->toBe(collect([$this->ops->lemonYakult->id, $this->ops->tapsilog->id])->sort()->values()->all())
        ->and($plans->pluck('name')->all())->toBe(['Drinks', 'Silog'])
        ->and($plans->pluck('lineage_id')->all())->toBe([$this->ops->drinks->lineage_id, $this->ops->silog->lineage_id])
        ->and(OperationPlanProduct::query()->where('branch_id', $test)->count())->toBe(2)
        ->and(Ingredient::query()->where('branch_id', $test)->orderBy('name')->pluck('name')->all())
        ->toBe(['Egg', 'Lemon', 'Nata', 'Purified Water', 'Rice', 'Syrup', 'Yakult'])
        ->and(Recipe::query()->where('branch_id', $test)->count())->toBe(3)
        ->and(ProductModifierEffect::query()->where('branch_id', $test)->count())->toBe(2)
        ->and(Recipe::query()->where('branch_id', $this->ops->branch->id)->count())->toBe($mainRecipes)
        ->and(recipeLines($this->test, $this->ops->lemonYakult, $this->ops->sizes['m']))->toBe(recipeLines($this->ops->branch, $this->ops->lemonYakult, $this->ops->sizes['m']))
        /** Recipe lines use only TEST's Ingredients. */
        ->and(RecipeLine::query()->whereIn('recipe_id', Recipe::query()->where('branch_id', $test)->select('id'))
            ->whereNotIn('ingredient_id', Ingredient::query()->where('branch_id', $test)->select('id'))->exists())->toBeFalse()
        /** Never physical or historical data. */
        ->and(BranchIngredientStock::query()->where('branch_id', $test)->exists())->toBeFalse()
        ->and(IngredientMovement::query()->where('branch_id', $test)->exists())->toBeFalse()
        ->and(PamamalengkePurchase::query()->where('branch_id', $test)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.setup_copied')->sole()->branch_id)->toBe($test)
        ->and(AuditLog::query()->where('action', 'branch_products.copied')->sole()->branch_id)->toBe($test);

    /** TEST can make nothing until it has its own stock; MAIN still sells from its stock. */
    $catalog = fn (Branch $branch): array => collect(app(BranchCatalog::class)->browse($branch)['products'])->firstWhere('id', $this->ops->lemonYakult->id);
    expect($catalog($this->test)['availability_reason'])->toBe('out_of_stock')
        ->and($catalog($this->ops->branch)['is_available'])->toBeTrue();
});

test('after the copy each branch changes independently', function () {
    copyFromMain($this)->assertRedirect();
    $testLemon = Ingredient::query()->where('branch_id', $this->test->id)->where('name', 'Lemon')->sole();
    $testPlan = OperationPlan::query()->where('branch_id', $this->test->id)->where('name', 'Drinks')->sole();
    $mainMedium = recipeLines($this->ops->branch, $this->ops->lemonYakult, $this->ops->sizes['m']);

    onBranch($this, $this->ops->owner, $this->test)->put(route('products.branches.update', [$this->ops->lemonYakult, $this->test]), [
        'price_override' => '75.00', 'is_available' => true, 'tracks_inventory' => false, 'low_stock_threshold' => null,
    ])->assertSessionHasNoErrors();
    onBranch($this, $this->ops->owner, $this->test)->put(route('operations.recipes.update', $this->ops->lemonYakult), [
        'size_option_id' => $this->ops->sizes['m']->id,
        'lines' => [['ingredient_id' => $testLemon->id, 'quantity' => '0.75']],
    ])->assertSessionHasNoErrors();
    onBranch($this, $this->ops->owner, $this->test)->put(route('operations.ingredients.update', $testLemon), [
        'name' => 'Lemon', 'icon' => 'lemon', 'base_unit' => 'pc', 'target_quantity' => '30', 'purchase_unit_name' => 'pc',
        'purchase_unit_size' => '1', 'purchase_unit_cost' => '12.00', 'replenishment_rule' => 'top_up', 'plan_ids' => [$testPlan->id],
    ])->assertSessionHasNoErrors();
    onBranch($this, $this->ops->owner, $this->test)->put(route('operations.plans.update', $testPlan), [
        'name' => 'Test drinks', 'icon' => 'glass', 'product_ids' => [$this->ops->lemonYakult->id],
    ])->assertSessionHasNoErrors();

    expect(recipeLines($this->test, $this->ops->lemonYakult, $this->ops->sizes['m']))->toBe(['Lemon' => '0.75'])
        ->and(recipeLines($this->ops->branch, $this->ops->lemonYakult, $this->ops->sizes['m']))->toBe($mainMedium)
        ->and((string) $this->ops->ingredients['lemon']->fresh()->purchase_unit_cost)->toBe('10.00')
        ->and((string) $testLemon->fresh()->purchase_unit_cost)->toBe('12.00')
        ->and($this->ops->drinks->fresh()->name)->toBe('Drinks')
        ->and(BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->lemonYakult->id)->value('price_override'))->toBeNull()
        ->and(BranchProduct::query()->where('branch_id', $this->test->id)->where('product_id', $this->ops->lemonYakult->id)->value('price_override'))->toBe('75.00');
});

test('a repeated copy keeps the destination setup unless replace is explicitly chosen, without duplicates', function () {
    copyFromMain($this)->assertRedirect();
    $testLemon = Ingredient::query()->where('branch_id', $this->test->id)->where('name', 'Lemon')->sole();
    onBranch($this, $this->ops->owner, $this->test)->put(route('operations.recipes.update', $this->ops->lemonYakult), [
        'size_option_id' => $this->ops->sizes['m']->id, 'lines' => [['ingredient_id' => $testLemon->id, 'quantity' => '0.75']],
    ])->assertSessionHasNoErrors();
    $counts = fn (): array => [
        BranchProduct::query()->where('branch_id', $this->test->id)->count(), Ingredient::query()->where('branch_id', $this->test->id)->count(),
        OperationPlan::query()->where('branch_id', $this->test->id)->count(), Recipe::query()->where('branch_id', $this->test->id)->count(),
        ProductModifierEffect::query()->where('branch_id', $this->test->id)->count(), OperationPlanProduct::query()->where('branch_id', $this->test->id)->count(),
    ];
    $before = $counts();

    copyFromMain($this)->assertRedirect()->assertSessionHasNoErrors();
    expect($counts())->toBe($before)
        ->and(recipeLines($this->test, $this->ops->lemonYakult, $this->ops->sizes['m']))->toBe(['Lemon' => '0.75']);

    copyFromMain($this, ['overwrite' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($counts())->toBe($before)
        ->and(recipeLines($this->test, $this->ops->lemonYakult, $this->ops->sizes['m']))
        ->toBe(recipeLines($this->ops->branch, $this->ops->lemonYakult, $this->ops->sizes['m']));
});

test('the standalone operations copy reviews first, copies only for products the destination sells and never stock', function () {
    onBranch($this, $this->ops->owner, $this->test)->post(route('products.branch-assortment.store'), ['product_ids' => [$this->ops->lemonYakult->id]])
        ->assertSessionHasNoErrors();
    $query = ['source_branch_id' => $this->ops->branch->id, 'sections' => ['plans', 'ingredients', 'recipes'], 'replace' => 0];

    onBranch($this, $this->ops->owner, $this->test)->getJson(route('operations.setup-copy.preview', $query))
        ->assertOk()
        ->assertJsonPath('destination.code', 'TEST')
        ->assertJsonPath('result.products', 1)
        ->assertJsonPath('result.plans.new', 2)
        ->assertJsonPath('result.ingredients.new', 7)
        ->assertJsonPath('result.recipes.recipes', 2)
        ->assertJsonPath('result.recipes.effects', 2)
        ->assertJsonPath('result.plan_products.assigned', 1);
    expect(Ingredient::query()->where('branch_id', $this->test->id)->exists())->toBeFalse();

    onBranch($this, $this->ops->owner, $this->test)->post(route('operations.setup-copy.store'), $query)->assertRedirect()->assertSessionHasNoErrors();
    expect(Ingredient::query()->where('branch_id', $this->test->id)->count())->toBe(7)
        ->and(Recipe::query()->where('branch_id', $this->test->id)->pluck('product_id')->unique()->values()->all())->toBe([$this->ops->lemonYakult->id])
        ->and(BranchIngredientStock::query()->where('branch_id', $this->test->id)->exists())->toBeFalse();

    /** A second copy finds everything already configured and changes nothing; replace restores MAIN's cost. */
    $testLemon = Ingredient::query()->where('branch_id', $this->test->id)->where('name', 'Lemon')->sole();
    $testLemon->update(['purchase_unit_cost' => '99.00']);
    onBranch($this, $this->ops->owner, $this->test)->getJson(route('operations.setup-copy.preview', $query))
        ->assertJsonPath('result.ingredients.new', 0)->assertJsonPath('result.ingredients.existing', 7)
        ->assertJsonPath('result.recipes.kept', 1)->assertJsonPath('result.recipes.products', 0);
    onBranch($this, $this->ops->owner, $this->test)->post(route('operations.setup-copy.store'), $query)->assertRedirect();
    expect((string) $testLemon->fresh()->purchase_unit_cost)->toBe('99.00');
    onBranch($this, $this->ops->owner, $this->test)->post(route('operations.setup-copy.store'), [...$query, 'sections' => ['ingredients'], 'replace' => 1])->assertRedirect();
    expect((string) $testLemon->fresh()->purchase_unit_cost)->toBe('10.00')
        ->and(Ingredient::query()->where('branch_id', $this->test->id)->count())->toBe(7);
});

test('copy authorization covers both branches and operations setup needs operations access', function () {
    (new RbacSeeder)->run();
    $superAdmin = $this->ops->superAdmin;
    $role = function (string $label, string $scope, array $permissions) use ($superAdmin): Role {
        $this->actingAs($superAdmin)->post(route('super-admin.access-control.custom-roles.store'), ['label' => $label, 'scope' => $scope, 'permissions' => $permissions])
            ->assertSessionHasNoErrors();

        return Role::query()->whereRaw('LOWER(label) = ?', [mb_strtolower((string) CustomRoles::normalizeLabel($label))])->sole();
    };
    $mainManager = $this->ops->user('owner');
    $mainManager->roles()->sync([$role('Main Manager', 'branch', ['products.manage', 'operations.manage'])->id]);
    $mainManager->branches()->attach($this->ops->branch, ['is_active' => true]);
    $catalogOnly = User::factory()->create();
    $catalogOnly->roles()->attach($role('Area Catalog', 'business', ['products.manage'])->id);
    $area = User::factory()->create();
    $area->roles()->attach($role('Area Manager', 'business', ['products.manage', 'operations.manage'])->id);

    /** A MAIN-only manager can neither read TEST nor write TEST (its session falls back to MAIN). */
    onBranch($this, $mainManager, $this->ops->branch)->getJson(route('operations.setup-copy.preview', [
        'source_branch_id' => $this->test->id, 'sections' => ['recipes'], 'replace' => 0,
    ]))->assertForbidden();
    onBranch($this, $mainManager, $this->ops->branch)->getJson(route('products.branch-assortment.copy.preview', ['source_branch_id' => $this->test->id]))->assertForbidden();

    /** Product copy alone is allowed without Operations access, but bringing Operations setup is not. */
    copyFromMain($this, [])->assertRedirect();
    BranchProduct::query()->where('branch_id', $this->test->id)->delete();
    $this->actingAs($catalogOnly)->withSession([ActiveBranchContext::SESSION_KEY => $this->test->id])->post(route('products.branch-assortment.copy'), [
        'source_branch_id' => $this->ops->branch->id, 'product_ids' => [$this->ops->tapsilog->id], 'overwrite' => false, 'copy_operations' => true,
    ])->assertForbidden();
    expect(BranchProduct::query()->where('branch_id', $this->test->id)->exists())->toBeFalse();

    /** A business-wide Area Manager may copy between any active Branches. */
    $this->actingAs($area)->withSession([ActiveBranchContext::SESSION_KEY => $this->test->id])->post(route('products.branch-assortment.copy'), [
        'source_branch_id' => $this->ops->branch->id, 'product_ids' => [$this->ops->tapsilog->id], 'overwrite' => false, 'copy_operations' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(Recipe::query()->where('branch_id', $this->test->id)->where('product_id', $this->ops->tapsilog->id)->count())->toBe(1);
});

test('a product removed from a branch cannot be newly sold through a stale draft, cart or qr id, while history stays', function () {
    $cashier = $this->ops->user('cashier', $this->test);
    StoreSession::factory()->for($this->test)->create(['opened_by_user_id' => $cashier->id]);
    copyFromMain($this)->assertRedirect();
    $this->ops->actAsOwnerOn($this->test);
    foreach (['Rice' => '1000', 'Egg' => '10', 'Purified Water' => '1000'] as $name => $quantity) {
        app(AdjustIngredientStock::class)->execute($this->ops->owner, Ingredient::query()->where('branch_id', $this->test->id)->where('name', $name)->sole(), [
            'mode' => 'count', 'quantity' => $quantity, 'reason' => 'Opening count', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
    $line = $this->ops->line($this->ops->tapsilog, 1);
    $pay = fn (array $extra = []) => app(PayNowOrder::class)->execute($cashier, $this->test, [
        'order_type' => 'take_out', 'customer_label' => 'TEST', 'items' => [$line], 'payment_method' => 'cash',
        'cash_received' => '999.00', 'cashless_amount' => null, 'idempotency_key' => (string) Str::uuid(), ...$extra,
    ]);
    $sold = $pay();
    $draft = app(CreatePosDraftOrder::class)->execute($cashier, $this->test, ['order_type' => 'take_out', 'customer_label' => 'Later', 'items' => [$line]]);
    $rice = fn (): string => (string) (float) BranchIngredientStock::query()->where('branch_id', $this->test->id)
        ->where('ingredient_id', Ingredient::query()->where('branch_id', $this->test->id)->where('name', 'Rice')->value('id'))->value('on_hand');
    expect($rice())->toBe('800');

    onBranch($this, $this->ops->owner, $this->test)->delete(route('products.branch-assortment.destroy'), ['product_ids' => [$this->ops->tapsilog->id]])
        ->assertSessionHasNoErrors();

    expect(fn () => $pay(['items' => [], 'draft_order_id' => $draft->id]))->toThrow(ValidationException::class, 'no longer available')
        ->and(fn () => $pay())->toThrow(ValidationException::class, 'no longer available')
        ->and(fn () => app(SubmitCustomerQrOrder::class)->execute($this->test, CustomerQrSession::factory()->for($this->test)->create(), [
            'idempotency_key' => (string) Str::uuid(), 'order_type' => 'take_out', 'customer_label' => 'QR', 'items' => [$line],
        ]))->toThrow(ValidationException::class, 'no longer available')
        ->and($rice())->toBe('800')
        ->and($draft->fresh()->committed_at)->toBeNull()
        ->and(Order::query()->where('branch_id', $this->test->id)->whereNotNull('committed_at')->pluck('id')->all())->toBe([$sold->id])
        ->and(OrderRecipeSnapshot::query()->where('order_id', $sold->id)->sole()->recipe_state->value)->toBe('recipe')
        ->and(IngredientMovement::query()->where('order_id', $sold->id)->count())->toBe(3)
        ->and(collect(app(BranchCatalog::class)->browse($this->test)['products'])->pluck('id')->all())->toBe([$this->ops->lemonYakult->id])
        /** MAIN still sells it. */
        ->and(collect(app(BranchCatalog::class)->browse($this->ops->branch)['products'])->firstWhere('id', $this->ops->tapsilog->id)['is_available'])->toBeTrue();
});

test('setup copies, recipe edits and removals signal only the changed branch', function () {
    copyFromMain($this)->assertRedirect();
    Event::fake([IngredientStockChanged::class, CustomerCatalogChanged::class, ReportsChanged::class]);
    $testLemon = Ingredient::query()->where('branch_id', $this->test->id)->where('name', 'Lemon')->sole();

    onBranch($this, $this->ops->owner, $this->test)->put(route('operations.recipes.update', $this->ops->lemonYakult), [
        'size_option_id' => $this->ops->sizes['m']->id, 'lines' => [['ingredient_id' => $testLemon->id, 'quantity' => '0.75']],
    ])->assertSessionHasNoErrors();
    onBranch($this, $this->ops->owner, $this->test)->delete(route('products.branch-assortment.destroy'), ['product_ids' => [$this->ops->tapsilog->id]])
        ->assertSessionHasNoErrors();

    foreach ([IngredientStockChanged::class, CustomerCatalogChanged::class, ReportsChanged::class] as $event) {
        Event::assertDispatched($event, fn (object $dispatched): bool => $dispatched->broadcastWith()['branch_id'] === $this->test->id);
        Event::assertNotDispatched($event, fn (object $dispatched): bool => $dispatched->broadcastWith()['branch_id'] === $this->ops->branch->id);
    }
    /** Invalidation only: ids, reason and time, never recipe lines, prices or stock. */
    Event::assertDispatched(ReportsChanged::class, fn (ReportsChanged $event): bool => array_keys($event->broadcastWith()) === ['event_id', 'event_type', 'branch_id', 'reason', 'occurred_at']);
});
