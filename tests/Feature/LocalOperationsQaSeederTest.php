<?php

use App\Actions\Operations\SaveIngredient;
use App\Actions\Orders\PayNowOrder;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\OperationPlan;
use App\Models\OperationPlanProduct;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\ExactQuantity;
use App\Support\ProductSizes;
use App\Support\RecipeCapacity;
use Database\Seeders\LocalDevelopmentSeeder;
use Database\Seeders\LocalOperationsQaSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(LocalDevelopmentSeeder::class);
});

/** @return array<string, string> ingredient name => display balance at the Branch */
function qaBalances(string $code): array
{
    return BranchIngredientStock::query()->whereHas('branch', fn ($query) => $query->where('code', $code))->with('ingredient:id,name')->get()
        ->mapWithKeys(fn (BranchIngredientStock $stock): array => [$stock->ingredient->name => ExactQuantity::display(ExactQuantity::parse($stock->on_hand))])
        ->sortKeys()->all();
}

/** @return array<string, int|null> Size => servings of one Product at the Branch */
function qaServings(string $code, string $product): array
{
    $productId = Product::query()->where('name', $product)->value('id');
    $availability = app(RecipeCapacity::class)->catalog(Branch::query()->where('code', $code)->sole(), [$productId]);

    return collect($availability[$productId]['sizes'])->pluck('capacity', 'name')->all();
}

test('the operations qa command seeds recipe-backed lemon drinks with different branch capacity', function () {
    $this->artisan('operations:seed-qa')->assertSuccessful();

    $products = Product::query()->whereIn('name', LocalOperationsQaSeeder::PRODUCTS)->get();
    $plan = OperationPlan::query()->where('name', 'Drinks')->sole();
    foreach ($products as $product) {
        expect(array_column(app(ProductSizes::class)->forProduct($product), 'name'))->toBe(['Small', 'Medium', 'Large'])
            ->and(OperationPlanProduct::query()->where('product_id', $product->id)->value('operation_plan_id'))->toBe($plan->id)
            ->and(BranchProduct::query()->where('product_id', $product->id)->where(fn ($query) => $query->where('tracks_inventory', true)->orWhere('is_available', false))->exists())->toBeFalse();
    }
    expect(Recipe::query()->count())->toBe(12)
        ->and(ProductModifierEffect::query()->count())->toBe(8)
        ->and(ModifierOption::query()->whereHas('modifierGroup', fn ($query) => $query->where('semantic_role', 'instruction'))->where('price_delta', '!=', 0)->exists())->toBeFalse();

    expect(qaBalances('MAIN'))->toBe([
        'Calamansi' => '2000', 'Cola Syrup' => '1500', 'Large Cup' => '10', 'Lemon' => '30', 'Medium Cup' => '15', 'Nata' => '1000',
        'Purified Water' => '10000', 'Small Cup' => '20', 'Sugar Syrup' => '2000', 'Yakult' => '24',
    ])->and(qaBalances('QAVE'))->toBe([
        'Calamansi' => '1200', 'Cola Syrup' => '1000', 'Large Cup' => '5', 'Lemon' => '18', 'Medium Cup' => '8', 'Nata' => '500',
        'Purified Water' => '7000', 'Small Cup' => '12', 'Sugar Syrup' => '1000', 'Yakult' => '12',
    ]);

    /** Lemon Yakult: MAIN is cup/yakult-limited (20, 15, 10); QAVE (12, 8, 5). */
    expect(qaServings('MAIN', 'Lemon Yakult'))->toBe(['Small' => 20, 'Medium' => 15, 'Large' => 10])
        ->and(qaServings('QAVE', 'Lemon Yakult'))->toBe(['Small' => 12, 'Medium' => 8, 'Large' => 5]);

    $owner = User::query()->where('email', 'owner@gmail.com')->sole();
    $this->actingAs($owner)->withSession([ActiveBranchContext::SESSION_KEY => Branch::query()->where('code', 'MAIN')->value('id')])
        ->get(route('operations.recipes', ['plan' => $plan->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.3.name', 'Lemon Yakult')
            ->where('products.3.inventory_mode', 'recipe')
            ->where('products.3.state', 'set')
            ->where('products.3.sizes.1.servings', 15)
            ->where('products.3.add_ons', fn ($addOns) => collect($addOns)->where('group_name', 'Drink Add-ons')->pluck('name')->sort()->values()->all() === ['Extra Yakult', 'Nata']
                && collect($addOns)->whereIn('name', ['No Ice', 'Less Sugar'])->isEmpty())
            ->where('products.3.instruction_groups', ['Instructions']));
});

test('running it again duplicates nothing and resets consumed stock with one count correction', function () {
    $this->artisan('operations:seed-qa')->assertSuccessful();
    $counts = fn (): array => [
        Product::query()->count(), ModifierGroup::query()->count(), ModifierOption::query()->count(), Ingredient::query()->count(),
        OperationPlanProduct::query()->count(), Recipe::query()->count(), RecipeLine::query()->count(), ProductModifierEffect::query()->count(),
    ];
    $before = $counts();
    $movements = IngredientMovement::query()->count();

    $this->artisan('operations:seed-qa')->assertSuccessful();
    expect($counts())->toBe($before)->and(IngredientMovement::query()->count())->toBe($movements);

    $main = Branch::query()->where('code', 'MAIN')->sole();
    $cashier = User::query()->where('email', 'cashier@gmail.com')->sole();
    StoreSession::factory()->for($main)->create(['opened_by_user_id' => $cashier->id]);
    $pure = Product::query()->where('name', 'Lemon Pure')->sole();
    $medium = ModifierOption::query()->where('name', 'Medium')->whereHas('modifierGroup', fn ($query) => $query->where('semantic_role', 'size'))->sole();
    app(PayNowOrder::class)->execute($cashier, $main, [
        'order_type' => 'take_out', 'customer_label' => 'QA', 'payment_method' => 'cash', 'cash_received' => '999.00', 'cashless_amount' => null,
        'idempotency_key' => (string) Str::uuid(),
        'items' => [['product_id' => $pure->id, 'quantity' => 2, 'notes' => null, 'modifiers' => [['group_id' => $medium->modifier_group_id, 'option_id' => $medium->id]]]],
    ]);
    expect(qaBalances('MAIN')['Lemon'])->toBe('29')->and(qaBalances('MAIN')['Medium Cup'])->toBe('13');

    $this->artisan('operations:seed-qa')->assertSuccessful();
    expect(qaBalances('MAIN')['Lemon'])->toBe('30')->and(qaBalances('MAIN')['Medium Cup'])->toBe('15')
        ->and(IngredientMovement::query()->where('movement_type', 'count_correction')->where('reason_code', 'Opening count')->count())
        ->toBe(20 + 4);
});

test('an existing ingredient with another locked unit is kept and the qa fallback is used', function () {
    $owner = User::query()->where('email', 'owner@gmail.com')->sole();
    $plan = OperationPlan::query()->create(['name' => 'Drinks', 'icon' => 'glass', 'created_by_user_id' => $owner->id]);
    session([ActiveBranchContext::SESSION_KEY => Branch::query()->where('code', 'MAIN')->value('id')]);
    app(SaveIngredient::class)->execute($owner, null, [
        'name' => 'Yakult', 'icon' => 'bottle', 'base_unit' => 'pack', 'target_quantity' => '3', 'replenishment_rule' => 'none',
        'plan_ids' => [$plan->id], 'initial_quantity' => '3',
    ]);

    $this->artisan('operations:seed-qa')->assertSuccessful();

    expect(Ingredient::query()->where('name', 'Yakult')->value('base_unit'))->toBe('pack')
        ->and(qaBalances('MAIN')['Yakult'])->toBe('3')
        ->and(qaBalances('MAIN')['Yakult Bottle'])->toBe('24')
        ->and(OperationPlan::query()->count())->toBe(1);
});

test('the qa command refuses to run outside local and testing', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('operations:seed-qa')->assertFailed();
    expect(Recipe::query()->count())->toBe(0);
});
