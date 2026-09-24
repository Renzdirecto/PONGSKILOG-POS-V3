<?php

use App\Actions\Catalog\UpsertBranchProduct;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\OperationPlan;
use App\Models\OperationPlanProduct;
use App\Models\OrderRecipeSnapshot;
use App\Models\PamamalengkeListEntry;
use App\Models\Product;
use App\Models\Recipe;
use App\Support\ActiveBranchContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create();
});

function opsAs(object $test, $user, ?Branch $branch)
{
    return $test->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch?->id]);
}

test('owner and super admin open every operations page with the url-addressable active plan', function (string $who) {
    $user = $who === 'owner' ? $this->ops->owner : $this->ops->superAdmin;
    foreach (['plans', 'overview', 'ingredients', 'recipes', 'stock', 'pamamalengke', 'purchases'] as $page) {
        opsAs($this, $user, $this->ops->branch)
            ->get(route('operations.'.$page, ['plan' => $this->ops->silog->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('operations/'.$page)
                ->where('operations.page', $page)
                ->where('operations.active_plan_id', $this->ops->silog->id)
                ->where('operations.branch.id', $this->ops->branch->id));
    }
})->with(['owner', 'super_admin']);

test('cashier, kitchen, guest and inactive users cannot open or change operations', function (string $who) {
    if ($who === 'guest') {
        $this->get(route('operations.plans'))->assertRedirect(route('login'));
        $this->post(route('operations.ingredients.store'), [])->assertRedirect(route('login'));

        return;
    }
    $user = match ($who) {
        'cashier' => $this->ops->cashier,
        'kitchen' => $this->ops->user('kitchen_staff', $this->ops->branch),
        'inactive' => tap($this->ops->user('owner'), fn ($user) => $user->forceFill(['is_active' => false])->save()),
    };

    /** The existing active-user middleware signs an inactive user out before Operations authorization runs. */
    $denied = fn ($response) => $who === 'inactive' ? $response->assertRedirect() : $response->assertForbidden();
    $denied(opsAs($this, $user, $this->ops->branch)->get(route('operations.plans')));
    $denied(opsAs($this, $user, $this->ops->branch)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), [
        'mode' => 'wastage', 'quantity' => '1', 'reason' => 'Spoiled', 'idempotency_key' => (string) Str::uuid(),
    ]));
    $denied(opsAs($this, $user, $this->ops->branch)->post(route('operations.plans.store'), ['name' => 'Hack', 'icon' => 'box', 'product_ids' => []]));
    expect(OperationPlan::query()->where('name', 'Hack')->exists())->toBeFalse();
    expect($this->ops->stock('lemon'))->toBe('29.5');
})->with(['cashier', 'kitchen', 'guest', 'inactive']);

test('all branches can read operations but never mutate physical stock or purchases', function () {
    opsAs($this, $this->ops->owner, null)->get(route('operations.stock', ['plan' => $this->ops->drinks->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('operations.branch', null)->where('ingredients', []));

    opsAs($this, $this->ops->owner, null)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), [
        'mode' => 'count', 'quantity' => '40', 'reason' => 'End-of-day count', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('branch');
    opsAs($this, $this->ops->owner, null)->post(route('operations.pamamalengke.confirm', $this->ops->drinks), [
        'idempotency_key' => (string) Str::uuid(), 'payment_source' => 'cash',
        'items' => [['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['lemon']->id, 'actual_quantity' => '1', 'actual_unit_cost' => '10.00']],
    ])->assertSessionHasErrors('branch');
    opsAs($this, $this->ops->owner, null)->post(route('operations.ingredients.store'), [
        'name' => 'Calamansi', 'icon' => 'lemon', 'base_unit' => 'pc', 'target_quantity' => '20', 'purchase_unit_name' => 'pc',
        'purchase_unit_size' => '1', 'purchase_unit_cost' => '2.00', 'replenishment_rule' => 'top_up', 'plan_ids' => [$this->ops->drinks->id],
        'initial_quantity' => '15',
    ])->assertSessionHasErrors('branch');

    expect($this->ops->stock('lemon'))->toBe('29.5')->and(Ingredient::query()->where('name', 'Calamansi')->exists())->toBeFalse();
});

test('another branch ingredient stock is separate and cross-branch list entries cannot be touched', function () {
    $other = Branch::factory()->create(['code' => 'EAST']);
    $entry = PamamalengkeListEntry::query()->create([
        'branch_id' => $other->id, 'operation_plan_id' => $this->ops->drinks->id, 'entry_type' => 'manual', 'name' => 'Ice',
        'quantity' => '1.0000', 'unit' => 'bag', 'created_by_user_id' => $this->ops->owner->id,
    ]);

    opsAs($this, $this->ops->owner, $this->ops->branch)->delete(route('operations.pamamalengke.manual.destroy', $entry))->assertNotFound();
    opsAs($this, $this->ops->owner, $other)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), [
        'mode' => 'count', 'quantity' => '4', 'reason' => 'Opening count', 'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect();

    expect($this->ops->stock('lemon'))->toBe('29.5')
        ->and($this->ops->stock('lemon', $other))->toBe('4')
        ->and(PamamalengkeListEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

test('a product belongs to one active plan; moving it only changes future sales', function () {
    expect(fn () => OperationPlanProduct::query()->create(['operation_plan_id' => $this->ops->silog->id, 'product_id' => $this->ops->coke->id]))
        ->toThrow(UniqueConstraintViolationException::class);

    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.plans.update', $this->ops->silog), [
        'name' => 'Silog', 'icon' => 'meal', 'product_ids' => [$this->ops->tapsilog->id, $this->ops->coke->id],
    ])->assertRedirect();

    expect(OperationPlanProduct::query()->where('product_id', $this->ops->coke->id)->sole()->operation_plan_id)->toBe($this->ops->silog->id)
        ->and(AuditLog::query()->where('action', 'operation_plan.updated')->latest('id')->first()->metadata['moved_products'][0]['from_plan_id'])->toBe($this->ops->drinks->id);
});

test('archiving a plan keeps history, releases its products and needs a unique active name', function () {
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.plans.store'), ['name' => 'drinks', 'icon' => 'box', 'product_ids' => []])
        ->assertSessionHasErrors('name');
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);

    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.plans.archive', $this->ops->drinks))->assertRedirect();

    expect($this->ops->drinks->fresh()->archived_at)->not->toBeNull()
        ->and(OperationPlanProduct::query()->where('operation_plan_id', $this->ops->drinks->id)->exists())->toBeFalse()
        ->and(IngredientMovement::query()->where('order_id', $order->id)->where('operation_plan_id', $this->ops->drinks->id)->count())->toBe(4);
});

test('an ingredient keeps exact fractional opening stock, target and purchase unit', function () {
    $lemon = $this->ops->ingredients['lemon']->fresh();
    $opening = IngredientMovement::query()->where('ingredient_id', $lemon->id)->sole();

    expect($this->ops->stock('lemon'))->toBe('29.5')
        ->and($opening->movement_type->value)->toBe('opening_balance')
        ->and($opening->created_by_user_id)->toBe($this->ops->owner->id)
        ->and(AuditLog::query()->where('action', 'ingredient.created')->where('auditable_id', $lemon->id)->exists())->toBeTrue();

    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.store'), [
        'name' => 'Sugar syrup', 'icon' => 'drop', 'base_unit' => 'ml', 'target_quantity' => '1000.125', 'purchase_unit_name' => 'bottle',
        'purchase_unit_size' => '750.5', 'purchase_unit_cost' => '99.50', 'replenishment_rule' => 'reorder', 'reorder_point' => '12.5',
        'plan_ids' => [$this->ops->drinks->id, $this->ops->silog->id], 'initial_quantity' => '0.125',
    ])->assertRedirect();
    $syrup = Ingredient::query()->where('name', 'Sugar syrup')->sole();

    expect($syrup->target_quantity)->toMatch('/^1000\.125/')
        ->and(BranchIngredientStock::query()->where('ingredient_id', $syrup->id)->sole()->on_hand)->toBe('0.1250')
        ->and($syrup->plans()->count())->toBe(2);
});

test('an ingredient update never rewrites stock and locks the base unit once used', function () {
    $input = [
        'name' => 'Lemon', 'icon' => 'lemon', 'base_unit' => 'kg', 'target_quantity' => '40', 'purchase_unit_name' => 'bag',
        'purchase_unit_size' => '10', 'purchase_unit_cost' => '95.00', 'replenishment_rule' => 'top_up', 'plan_ids' => [$this->ops->drinks->id],
        'initial_quantity' => '999',
    ];
    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.ingredients.update', $this->ops->ingredients['lemon']), $input)
        ->assertSessionHasErrors('base_unit');

    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.ingredients.update', $this->ops->ingredients['lemon']), [...$input, 'base_unit' => 'pc'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->ops->stock('lemon'))->toBe('29.5')
        ->and(IngredientMovement::query()->where('ingredient_id', $this->ops->ingredients['lemon']->id)->count())->toBe(1)
        ->and($this->ops->ingredients['lemon']->fresh()->purchase_unit_cost)->toBe('95.00');
});

test('invalid replenishment settings are rejected on the server', function (array $change, string $field) {
    if (($change['plan_ids'] ?? null) === ['archived']) {
        $change['plan_ids'] = [OperationPlan::factory()->archived()->create()->id];
    }
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.store'), [
        'name' => 'Calamansi', 'icon' => 'lemon', 'base_unit' => 'pc', 'target_quantity' => '20', 'purchase_unit_name' => 'pc',
        'purchase_unit_size' => '1', 'purchase_unit_cost' => '2.00', 'replenishment_rule' => 'reorder', 'reorder_point' => '5',
        'plan_ids' => [$this->ops->drinks->id], ...$change,
    ])->assertSessionHasErrors($field);
})->with([
    'reorder above target' => [['reorder_point' => '20'], 'reorder_point'],
    'zero target' => [['target_quantity' => '0'], 'target_quantity'],
    'five decimals' => [['target_quantity' => '1.12345'], 'target_quantity'],
    'no plan' => [['plan_ids' => []], 'plan_ids'],
    'archived plan' => [['plan_ids' => ['archived']], 'plan_ids'],
    'rule without unit' => [['purchase_unit_name' => null, 'purchase_unit_size' => null], 'purchase_unit_name'],
    'unit name without size' => [['purchase_unit_size' => null], 'purchase_unit_size'],
    'duplicate name' => [['name' => 'lemon'], 'name'],
]);

test('an ingredient used by a recipe cannot be archived; archiving never deletes history', function () {
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.archive', $this->ops->ingredients['lemon']))
        ->assertSessionHasErrors('ingredient');

    $mint = $this->ops->ingredient('Mint', 'g', '100', 'pack', '50', '30.00', 'top_up', null, [$this->ops->drinks], '10');
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.archive', $mint))->assertRedirect();

    expect($mint->fresh()->archived_at)->not->toBeNull()
        ->and(IngredientMovement::query()->where('ingredient_id', $mint->id)->count())->toBe(1)
        ->and(BranchIngredientStock::query()->where('ingredient_id', $mint->id)->exists())->toBeTrue();
});

test('recipes attach only to existing product sizes and active ingredients', function (string $case) {
    $payload = ['size_option_id' => $this->ops->sizes['s']->id, 'lines' => [['ingredient_id' => $this->ops->ingredients['lemon']->id, 'quantity' => '0.25']]];
    $product = $this->ops->lemonYakult;
    $field = 'size_option_id';
    match ($case) {
        'size of another product' => $payload['size_option_id'] = (string) Str::uuid(),
        'missing size for sized product' => $payload['size_option_id'] = null,
        'size for product without sizes' => $product = $this->ops->tapsilog,
        'zero quantity' => [$payload['lines'][0]['quantity'], $field] = ['0', 'lines.0.quantity'],
        'archived ingredient' => [$payload['lines'][0]['ingredient_id'], $field] = [Ingredient::factory()->create(['archived_at' => now()])->id, 'lines'],
        'product stock product' => [$product, $payload['size_option_id'], $field] = [$this->ops->coke, null, 'product'],
    };

    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.recipes.update', $product), $payload)->assertSessionHasErrors($field);
})->with(['size of another product', 'missing size for sized product', 'size for product without sizes', 'zero quantity', 'archived ingredient', 'product stock product']);

test('saving a recipe is exact and audited; an empty recipe removes it', function () {
    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.recipes.update', $this->ops->lemonYakult), [
        'size_option_id' => $this->ops->sizes['s']->id,
        'lines' => [['ingredient_id' => $this->ops->ingredients['lemon']->id, 'quantity' => '0.125'], ['ingredient_id' => $this->ops->ingredients['syrup']->id, 'quantity' => '12.5']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $recipe = Recipe::query()->where('product_id', $this->ops->lemonYakult->id)->where('size_key', $this->ops->sizes['s']->id)->sole();
    expect($recipe->lines()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'recipe.saved')->where('auditable_id', $this->ops->lemonYakult->id)->exists())->toBeTrue();

    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.recipes.update', $this->ops->lemonYakult), [
        'size_option_id' => $this->ops->sizes['s']->id, 'lines' => [],
    ])->assertRedirect();
    expect(Recipe::query()->whereKey($recipe->id)->exists())->toBeFalse();
});

test('no recipe needed and product stock tracking never combine with a recipe', function () {
    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.recipes.mode', $this->ops->lemonYakult), ['no_recipe_needed' => true])
        ->assertSessionHasErrors('product');

    expect(fn () => app(UpsertBranchProduct::class)->execute($this->ops->owner, $this->ops->branch, $this->ops->lemonYakult, [
        'price_override' => null, 'is_available' => true, 'tracks_inventory' => true, 'low_stock_threshold' => null,
    ]))->toThrow(ValidationException::class);

    $water = Product::factory()->create(['name' => 'Bottled Water']);
    BranchProduct::factory()->for($this->ops->branch)->for($water)->create(['tracks_inventory' => false]);
    opsAs($this, $this->ops->owner, $this->ops->branch)->put(route('operations.recipes.mode', $water), ['no_recipe_needed' => true])->assertRedirect();
    $order = $this->ops->payNow([$this->ops->line($water, 1)]);

    expect($water->fresh()->no_recipe_needed)->toBeTrue()
        ->and(OrderRecipeSnapshot::query()->where('order_id', $order->id)->sole()->recipe_state->value)->toBe('not_needed')
        ->and(IngredientMovement::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

test('wastage and count correction append exact audited movements and never rewrite the balance', function () {
    $key = (string) Str::uuid();
    $wastage = ['mode' => 'wastage', 'quantity' => '0.5', 'reason' => 'Spoiled', 'note' => 'Soft', 'idempotency_key' => $key];
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), $wastage)->assertRedirect();
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), $wastage)->assertRedirect();

    expect($this->ops->stock('lemon'))->toBe('29')
        ->and(IngredientMovement::query()->where('movement_type', 'wastage')->count())->toBe(1);

    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), [
        'mode' => 'count', 'quantity' => '27.25', 'reason' => 'End-of-day count', 'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect();

    $correction = IngredientMovement::query()->where('movement_type', 'count_correction')->sole();
    expect($this->ops->stock('lemon'))->toBe('27.25')
        ->and($correction->quantity_delta)->toBe('-1.7500')
        ->and(AuditLog::query()->where('action', 'ingredient.count_corrected')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'ingredient.wastage_recorded')->count())->toBe(1);
});

test('wastage cannot exceed stock and a matching count records nothing', function () {
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), [
        'mode' => 'wastage', 'quantity' => '30', 'reason' => 'Spoiled', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('quantity');
    opsAs($this, $this->ops->owner, $this->ops->branch)->post(route('operations.ingredients.adjust', $this->ops->ingredients['lemon']), [
        'mode' => 'count', 'quantity' => '29.5', 'reason' => 'End-of-day count', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('quantity');

    expect(IngredientMovement::query()->whereIn('movement_type', ['wastage', 'count_correction'])->exists())->toBeFalse();
});

test('catalog inventory shows products and ingredients from the one canonical ingredient stock', function () {
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);

    opsAs($this, $this->ops->owner, $this->ops->branch)->get(route('inventory.index', ['type' => 'ingredients']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/index')
            ->where('filters.type', 'ingredients')
            ->where('products.total', 0)
            ->where('ingredients', fn ($rows) => collect($rows)->firstWhere('name', 'Lemon')['stock']['current'] === '29')
            ->where('ingredientCount', 6));

    opsAs($this, $this->ops->owner, $this->ops->branch)->get(route('operations.ingredients'))
        ->assertInertia(fn (Assert $page) => $page->where('ingredients', fn ($rows) => collect($rows)->firstWhere('name', 'Lemon')['stock']['current'] === '29'));
    opsAs($this, $this->ops->owner, $this->ops->branch)->get(route('inventory.index', ['type' => 'products']))
        ->assertInertia(fn (Assert $page) => $page->where('ingredients', [])->where('products.total', 3));
});
