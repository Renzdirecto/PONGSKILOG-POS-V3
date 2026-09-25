<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\BranchCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Phase 18 pass #2.1 cutover from the shared Operations setup and implicit assortment to Branch-owned configuration,
 * run against a realistic pre-cutover dataset: MAIN (oldest) and QAVE, shared Ingredients/Plan/Recipe/Add-on effect,
 * Branch stock and movements at both Branches, and a QAVE Order, purchase, working list and Giveaway that reference the
 * shared records.
 */
function cutoverMigration(): object
{
    return require database_path('migrations/2026_09_25_112126_make_branch_catalog_and_operations_independent.php');
}

/**
 * @return array<string, mixed> ids of the seeded pre-cutover records
 */
function seedPreCutover(): array
{
    $now = now();
    $owner = User::factory()->create();
    $main = Branch::factory()->create(['code' => 'MAIN', 'created_at' => $now->copy()->subDays(2)]);
    $qave = Branch::factory()->create(['code' => 'QAVE', 'created_at' => $now->copy()->subDay()]);
    $category = Category::factory()->create();
    $addOns = ModifierGroup::factory()->create(['name' => 'Add-ons', 'semantic_role' => null]);
    $extra = ModifierOption::factory()->for($addOns)->create(['name' => 'Extra Yakult']);
    $drink = Product::factory()->for($category)->create(['name' => 'Lemon Yakult']);
    $bottled = Product::factory()->for($category)->create(['name' => 'Bottled Water']);
    $hidden = Product::factory()->for($category)->create(['name' => 'Halo-halo']);
    $drink->modifierGroups()->attach($addOns);
    DB::table('products')->where('id', $bottled->id)->update(['no_recipe_needed' => true]);
    /** Existing Branch overrides: a QAVE price, and a Product QAVE had made unavailable. */
    DB::table('branch_products')->insert([
        ['id' => (string) Str::uuid(), 'branch_id' => $qave->id, 'product_id' => $drink->id, 'price_override' => '50.00', 'is_available' => true, 'tracks_inventory' => false, 'low_stock_threshold' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'branch_id' => $qave->id, 'product_id' => $hidden->id, 'price_override' => null, 'is_available' => false, 'tracks_inventory' => false, 'low_stock_threshold' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $ingredient = fn (string $name, string $unit, string $cost): string => tap((string) Str::uuid(), fn (string $id) => DB::table('ingredients')->insert([
        'id' => $id, 'name' => $name, 'icon' => 'box', 'base_unit' => $unit, 'target_quantity' => '10.0000', 'purchase_unit_name' => 'pc',
        'purchase_unit_size' => '1.0000', 'purchase_unit_cost' => $cost, 'replenishment_rule' => 'top_up', 'reorder_point' => null,
        'archived_at' => null, 'created_by_user_id' => $owner->id, 'updated_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now,
    ]));
    $lemon = $ingredient('Lemon', 'pc', '10.00');
    $yakult = $ingredient('Yakult', 'pc', '11.00');
    $plan = (string) Str::uuid();
    DB::table('operation_plans')->insert(['id' => $plan, 'name' => 'Drinks', 'description' => null, 'icon' => 'glass', 'archived_at' => null, 'created_by_user_id' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('operation_plan_products')->insert(['id' => (string) Str::uuid(), 'operation_plan_id' => $plan, 'product_id' => $drink->id, 'created_at' => $now, 'updated_at' => $now]);
    foreach ([$lemon, $yakult] as $id) {
        DB::table('operation_plan_ingredients')->insert(['id' => (string) Str::uuid(), 'operation_plan_id' => $plan, 'ingredient_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
    }
    $recipe = (string) Str::uuid();
    DB::table('recipes')->insert(['id' => $recipe, 'product_id' => $drink->id, 'size_modifier_option_id' => null, 'size_key' => 'base', 'updated_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('recipe_lines')->insert([
        ['id' => (string) Str::uuid(), 'recipe_id' => $recipe, 'ingredient_id' => $lemon, 'quantity' => '0.5000', 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'recipe_id' => $recipe, 'ingredient_id' => $yakult, 'quantity' => '1.0000', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $effect = (string) Str::uuid();
    DB::table('product_modifier_effects')->insert(['id' => $effect, 'product_id' => $drink->id, 'modifier_option_id' => $extra->id, 'updated_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('product_modifier_effect_lines')->insert(['id' => (string) Str::uuid(), 'product_modifier_effect_id' => $effect, 'ingredient_id' => $yakult, 'quantity' => '1.0000', 'created_at' => $now, 'updated_at' => $now]);

    /** Physical stock and history at both Branches (QAVE sold one Lemon Yakult with Extra Yakult). */
    foreach ([[$main, $lemon, '10.0000'], [$main, $yakult, '20.0000'], [$qave, $lemon, '4.5000'], [$qave, $yakult, '8.0000']] as [$branch, $id, $onHand]) {
        DB::table('branch_ingredient_stocks')->insert(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'ingredient_id' => $id, 'on_hand' => $onHand, 'version' => 2, 'created_at' => $now, 'updated_at' => $now]);
    }
    $session = StoreSession::factory()->for($qave)->create(['opened_by_user_id' => $owner->id]);
    $order = Order::factory()->for($qave)->create(['store_session_id' => $session->id, 'commercial_status' => 'completed', 'payment_status' => 'paid', 'committed_at' => $now]);
    $snapshot = (string) Str::uuid();
    DB::table('order_recipe_snapshots')->insert(['id' => $snapshot, 'order_id' => $order->id, 'branch_id' => $qave->id, 'product_id' => $drink->id, 'size_modifier_option_id' => null, 'size_key' => 'base', 'product_name_snapshot' => 'Lemon Yakult', 'size_name_snapshot' => null, 'recipe_state' => 'recipe', 'operation_plan_id' => $plan, 'created_at' => $now]);
    DB::table('order_recipe_snapshot_lines')->insert([
        ['id' => (string) Str::uuid(), 'order_recipe_snapshot_id' => $snapshot, 'ingredient_id' => $lemon, 'quantity_per_unit' => '0.5000', 'cost_basis_cents' => 1000, 'cost_basis_quantity' => '1.0000'],
        ['id' => (string) Str::uuid(), 'order_recipe_snapshot_id' => $snapshot, 'ingredient_id' => $yakult, 'quantity_per_unit' => '1.0000', 'cost_basis_cents' => 1100, 'cost_basis_quantity' => '1.0000'],
    ]);
    $modifier = (string) Str::uuid();
    DB::table('order_recipe_snapshot_modifiers')->insert(['id' => $modifier, 'order_recipe_snapshot_id' => $snapshot, 'modifier_option_id' => $extra->id, 'option_name_snapshot' => 'Extra Yakult', 'group_name_snapshot' => 'Add-ons', 'created_at' => $now]);
    DB::table('order_recipe_snapshot_modifier_lines')->insert(['id' => (string) Str::uuid(), 'order_recipe_snapshot_modifier_id' => $modifier, 'ingredient_id' => $yakult, 'quantity_per_selection' => '1.0000', 'cost_basis_cents' => 1100, 'cost_basis_quantity' => '1.0000']);
    $movement = fn (Branch $branch, string $id, string $type, string $delta, string $after, array $extra = []): string => tap((string) Str::uuid(), fn (string $movementId) => DB::table('ingredient_movements')->insert([
        'id' => $movementId, 'branch_id' => $branch->id, 'ingredient_id' => $id, 'movement_type' => $type, 'quantity_delta' => $delta,
        'balance_after' => $after, 'estimated_cost_cents' => null, 'order_id' => null, 'order_recipe_snapshot_id' => null, 'operation_plan_id' => null,
        'pamamalengke_purchase_id' => null, 'store_session_expense_id' => null, 'reason_code' => null, 'reason' => 'seed', 'created_by_user_id' => $owner->id,
        'idempotency_key' => null, 'created_at' => $now, ...$extra,
    ]));
    $movement($main, $lemon, 'opening_balance', '10.0000', '10.0000');
    $movement($qave, $lemon, 'opening_balance', '5.0000', '5.0000');
    $movement($qave, $lemon, 'sale_consumption', '-0.5000', '4.5000', ['order_id' => $order->id, 'order_recipe_snapshot_id' => $snapshot, 'operation_plan_id' => $plan, 'estimated_cost_cents' => 500]);
    $movement($qave, $yakult, 'sale_consumption', '-2.0000', '8.0000', ['order_id' => $order->id, 'order_recipe_snapshot_id' => $snapshot, 'operation_plan_id' => $plan, 'estimated_cost_cents' => 2200]);
    $expense = StoreSessionExpense::factory()->create(['branch_id' => $qave->id, 'store_session_id' => $session->id]);
    $purchase = (string) Str::uuid();
    DB::table('pamamalengke_purchases')->insert(['id' => $purchase, 'branch_id' => $qave->id, 'store_session_id' => $session->id, 'operation_plan_id' => $plan, 'store_session_expense_id' => $expense->id, 'estimated_total' => '10.00', 'estimate_complete' => true, 'note' => null, 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid(), 'intent_hash' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('pamamalengke_purchase_items')->insert(['id' => (string) Str::uuid(), 'pamamalengke_purchase_id' => $purchase, 'line_type' => 'ingredient', 'ingredient_id' => $lemon, 'name_snapshot' => 'Lemon', 'unit_label' => 'pc', 'was_recommended' => true, 'recommended_quantity' => '1.0000', 'actual_quantity' => '1.0000', 'estimated_unit_cost' => '10.00', 'actual_unit_cost' => '10.00', 'line_total' => '10.00', 'purchase_unit_size' => '1.0000', 'base_quantity' => '1.0000', 'ingredient_movement_id' => null, 'note' => null, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('pamamalengke_list_entries')->insert(['id' => (string) Str::uuid(), 'branch_id' => $qave->id, 'operation_plan_id' => $plan, 'entry_type' => 'skip', 'ingredient_id' => $yakult, 'name' => null, 'quantity' => null, 'unit' => null, 'estimated_unit_cost' => null, 'note' => null, 'created_by_user_id' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
    $giveaway = (string) Str::uuid();
    DB::table('store_session_giveaways')->insert(['id' => $giveaway, 'branch_id' => $qave->id, 'store_session_id' => $session->id, 'product_id' => $drink->id, 'product_name_snapshot' => 'Lemon Yakult', 'size_key' => 'base', 'size_name_snapshot' => null, 'selections' => '[]', 'quantity' => 1, 'stock_mode' => 'recipe', 'stock_basis' => json_encode(['size_key' => 'base', 'base' => [['ingredient_id' => $lemon, 'name' => 'Lemon', 'unit' => 'pc', 'quantity' => '0.5']], 'add_ons' => []]), 'inventory_movement_id' => null, 'reason_code' => 'complimentary', 'note' => null, 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid(), 'intent_hash' => str_repeat('b', 64), 'created_at' => $now, 'updated_at' => $now]);

    return compact('main', 'qave', 'drink', 'bottled', 'hidden', 'extra', 'lemon', 'yakult', 'plan', 'recipe', 'effect', 'order', 'snapshot', 'purchase', 'giveaway');
}

beforeEach(function () {
    /**
     * A throw-away SQLite file database migrated up to (not including) the cutover, used as the default connection so
     * the real forward migration runs on legacy data outside the RefreshDatabase transaction.
     */
    $this->cutoverFile = tempnam(sys_get_temp_dir(), 'cutover');
    config(['database.connections.cutover' => [
        'driver' => 'sqlite', 'database' => $this->cutoverFile, 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    $this->previousConnection = config('database.default');
    config(['database.default' => 'cutover']);
    DB::setDefaultConnection('cutover');
    $migrator = app('migrator');
    $migrator->setConnection('cutover');
    if (! $migrator->repositoryExists()) {
        $migrator->getRepository()->createRepository();
    }
    $files = collect(glob(database_path('migrations/*.php')))
        ->reject(fn (string $file): bool => str_contains($file, 'make_branch_catalog_and_operations_independent'))
        ->sort()->values()->all();
    $migrator->run($files);
    expect(Schema::hasColumn('products', 'no_recipe_needed'))->toBeTrue()
        ->and(Schema::hasColumn('ingredients', 'branch_id'))->toBeFalse();
});

afterEach(function () {
    DB::purge('cutover');
    config(['database.default' => $this->previousConnection]);
    DB::setDefaultConnection($this->previousConnection);
    app('migrator')->setConnection($this->previousConnection);
    @unlink($this->cutoverFile);
});

/** No row anywhere references a missing parent after the cutover. */
function foreignKeysAreIntact(): bool
{
    return DB::select('PRAGMA foreign_key_check') === [];
}

test('the cutover materializes each existing branch assortment and moves the recipe mode to the branch', function () {
    $seed = seedPreCutover();

    cutoverMigration()->up();

    expect(foreignKeysAreIntact())->toBeTrue();
    $rows = DB::table('branch_products')->get()->groupBy('branch_id');
    expect(Schema::hasColumn('products', 'no_recipe_needed'))->toBeFalse()
        ->and($rows[$seed['main']->id])->toHaveCount(3)
        ->and($rows[$seed['qave']->id])->toHaveCount(3)
        /** Existing configuration is kept: QAVE price and QAVE's unavailable Product (never re-enabled). */
        ->and((string) $rows[$seed['qave']->id]->firstWhere('product_id', $seed['drink']->id)->price_override)->toContain('50')
        ->and((bool) $rows[$seed['qave']->id]->firstWhere('product_id', $seed['hidden']->id)->is_available)->toBeFalse()
        ->and($rows->flatten(1)->where('product_id', $seed['bottled']->id)->every(fn ($row): bool => (bool) $row->no_recipe_needed))->toBeTrue()
        ->and($rows->flatten(1)->where('product_id', $seed['drink']->id)->every(fn ($row): bool => ! $row->no_recipe_needed))->toBeTrue()
        ->and(DB::table('products')->count())->toBe(3)
        ->and(DB::table('categories')->count())->toBe(1)
        ->and(DB::table('modifier_groups')->count())->toBe(1);

    /** Current POS behaviour is preserved at both Branches. */
    $names = fn (Branch $branch): array => collect(app(BranchCatalog::class)->browse($branch)['products'])->where('is_available', true)->pluck('name')->sort()->values()->all();
    expect($names($seed['main']))->toBe(['Bottled Water', 'Halo-halo', 'Lemon Yakult'])
        ->and($names($seed['qave']))->toBe(['Bottled Water', 'Lemon Yakult']);
});

test('the cutover gives every branch an independent operations setup through an explicit id map', function () {
    $seed = seedPreCutover();

    cutoverMigration()->up();

    $main = $seed['main']->id;
    $qave = $seed['qave']->id;
    $ingredients = DB::table('ingredients')->get();
    $qaveLemon = $ingredients->where('branch_id', $qave)->firstWhere('name', 'Lemon');
    $qaveYakult = $ingredients->where('branch_id', $qave)->firstWhere('name', 'Yakult');
    $qavePlan = DB::table('operation_plans')->where('branch_id', $qave)->sole();

    /** MAIN (oldest) keeps the original ids; QAVE gets copies that remember their lineage. */
    expect($ingredients)->toHaveCount(4)
        ->and($ingredients->where('branch_id', $main)->pluck('id')->sort()->values()->all())->toBe(collect([$seed['lemon'], $seed['yakult']])->sort()->values()->all())
        ->and($qaveLemon->id)->not->toBe($seed['lemon'])
        ->and($qaveLemon->lineage_id)->toBe($seed['lemon'])
        ->and((string) $qaveLemon->purchase_unit_cost)->toContain('10')
        ->and(DB::table('operation_plans')->where('branch_id', $main)->sole()->id)->toBe($seed['plan'])
        ->and($qavePlan->lineage_id)->toBe($seed['plan'])
        ->and(DB::table('operation_plan_products')->where('branch_id', $qave)->sole()->operation_plan_id)->toBe($qavePlan->id)
        ->and(DB::table('operation_plan_ingredients')->where('branch_id', $qave)->pluck('ingredient_id')->sort()->values()->all())
        ->toBe(collect([$qaveLemon->id, $qaveYakult->id])->sort()->values()->all());

    /** Recipes and Add-on effects: one per Branch, each using only its own Branch's Ingredients. */
    $qaveRecipe = DB::table('recipes')->where('branch_id', $qave)->sole();
    expect(DB::table('recipes')->where('branch_id', $main)->sole()->id)->toBe($seed['recipe'])
        ->and(DB::table('recipe_lines')->where('recipe_id', $qaveRecipe->id)->pluck('quantity', 'ingredient_id')->map(fn ($quantity): float => (float) $quantity)->sortKeys()->all())
        ->toBe(collect([$qaveLemon->id => 0.5, $qaveYakult->id => 1.0])->sortKeys()->all())
        ->and(DB::table('recipe_lines')->where('recipe_id', $seed['recipe'])->pluck('ingredient_id')->sort()->values()->all())
        ->toBe(collect([$seed['lemon'], $seed['yakult']])->sort()->values()->all())
        ->and(DB::table('product_modifier_effect_lines')->whereIn('product_modifier_effect_id', DB::table('product_modifier_effects')->where('branch_id', $qave)->select('id'))->sole()->ingredient_id)
        ->toBe($qaveYakult->id)
        ->and(DB::table('product_modifier_effects')->where('branch_id', $main)->sole()->id)->toBe($seed['effect']);
});

test('the cutover keeps every quantity, balance, cost and snapshot and re-points qave history to its own records', function () {
    $seed = seedPreCutover();
    $before = [
        'stocks' => DB::table('branch_ingredient_stocks')->orderBy('branch_id')->orderBy('on_hand')->get(['branch_id', 'on_hand', 'version'])->map(fn ($row): array => (array) $row)->all(),
        'movements' => DB::table('ingredient_movements')->orderBy('id')->get(['id', 'branch_id', 'movement_type', 'quantity_delta', 'balance_after', 'estimated_cost_cents', 'order_id'])->map(fn ($row): array => (array) $row)->all(),
        'lines' => DB::table('order_recipe_snapshot_lines')->orderBy('id')->get(['id', 'quantity_per_unit', 'cost_basis_cents', 'cost_basis_quantity'])->map(fn ($row): array => (array) $row)->all(),
        'orders' => DB::table('orders')->get(['id', 'total', 'subtotal'])->map(fn ($row): array => (array) $row)->all(),
    ];

    cutoverMigration()->up();

    $qave = $seed['qave']->id;
    $qaveLemon = DB::table('ingredients')->where('branch_id', $qave)->where('name', 'Lemon')->value('id');
    $qaveYakult = DB::table('ingredients')->where('branch_id', $qave)->where('name', 'Yakult')->value('id');
    $qavePlan = DB::table('operation_plans')->where('branch_id', $qave)->value('id');
    expect(DB::table('branch_ingredient_stocks')->orderBy('branch_id')->orderBy('on_hand')->get(['branch_id', 'on_hand', 'version'])->map(fn ($row): array => (array) $row)->all())->toBe($before['stocks'])
        ->and(DB::table('ingredient_movements')->orderBy('id')->get(['id', 'branch_id', 'movement_type', 'quantity_delta', 'balance_after', 'estimated_cost_cents', 'order_id'])->map(fn ($row): array => (array) $row)->all())->toBe($before['movements'])
        ->and(DB::table('order_recipe_snapshot_lines')->orderBy('id')->get(['id', 'quantity_per_unit', 'cost_basis_cents', 'cost_basis_quantity'])->map(fn ($row): array => (array) $row)->all())->toBe($before['lines'])
        ->and(DB::table('orders')->get(['id', 'total', 'subtotal'])->map(fn ($row): array => (array) $row)->all())->toBe($before['orders'])
        ->and(DB::table('ingredient_movements')->count())->toBe(4)
        ->and(DB::table('order_recipe_snapshots')->count())->toBe(1);

    /** Every QAVE row now names QAVE's own Ingredients and Plan; MAIN rows still name the originals. */
    expect(DB::table('branch_ingredient_stocks')->where('branch_id', $qave)->pluck('ingredient_id')->sort()->values()->all())->toBe(collect([$qaveLemon, $qaveYakult])->sort()->values()->all())
        ->and(DB::table('branch_ingredient_stocks')->where('branch_id', $seed['main']->id)->pluck('ingredient_id')->sort()->values()->all())->toBe(collect([$seed['lemon'], $seed['yakult']])->sort()->values()->all())
        ->and(DB::table('ingredient_movements')->where('branch_id', $qave)->whereNotIn('ingredient_id', [$qaveLemon, $qaveYakult])->exists())->toBeFalse()
        ->and(DB::table('ingredient_movements')->where('branch_id', $qave)->whereNotNull('operation_plan_id')->pluck('operation_plan_id')->unique()->all())->toBe([$qavePlan])
        ->and(DB::table('order_recipe_snapshots')->where('id', $seed['snapshot'])->value('operation_plan_id'))->toBe($qavePlan)
        ->and(DB::table('order_recipe_snapshot_lines')->where('order_recipe_snapshot_id', $seed['snapshot'])->pluck('ingredient_id')->sort()->values()->all())->toBe(collect([$qaveLemon, $qaveYakult])->sort()->values()->all())
        ->and(DB::table('order_recipe_snapshot_modifier_lines')->value('ingredient_id'))->toBe($qaveYakult)
        ->and(DB::table('pamamalengke_purchases')->where('id', $seed['purchase'])->value('operation_plan_id'))->toBe($qavePlan)
        ->and(DB::table('pamamalengke_purchase_items')->value('ingredient_id'))->toBe($qaveLemon)
        ->and(DB::table('pamamalengke_list_entries')->sole()->ingredient_id)->toBe($qaveYakult)
        ->and(DB::table('pamamalengke_list_entries')->sole()->operation_plan_id)->toBe($qavePlan)
        ->and(json_decode((string) DB::table('store_session_giveaways')->where('id', $seed['giveaway'])->value('stock_basis'), true)['base'][0]['ingredient_id'])->toBe($qaveLemon);
});

test('a branch created after the cutover starts with an empty assortment and no operations setup', function () {
    seedPreCutover();
    cutoverMigration()->up();

    $test = Branch::factory()->create(['code' => 'TEST']);

    expect(DB::table('branch_products')->where('branch_id', $test->id)->count())->toBe(0)
        ->and(DB::table('ingredients')->where('branch_id', $test->id)->count())->toBe(0)
        ->and(DB::table('operation_plans')->where('branch_id', $test->id)->count())->toBe(0)
        ->and(DB::table('recipes')->where('branch_id', $test->id)->count())->toBe(0)
        ->and(DB::table('branch_ingredient_stocks')->where('branch_id', $test->id)->count())->toBe(0)
        ->and(app(BranchCatalog::class)->browse($test))->toBe(['categories' => [], 'products' => []]);
});

test('rolling back refuses once branch-owned setup exists, so no branch configuration is silently merged', function () {
    seedPreCutover();
    cutoverMigration()->up();

    expect(fn () => cutoverMigration()->down())->toThrow(RuntimeException::class, 'cannot be merged back');
});
