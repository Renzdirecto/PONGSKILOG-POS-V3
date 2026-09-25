<?php

use App\Actions\Operations\SaveOperationPlan;
use App\Actions\Operations\SaveRecipe;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
use App\Models\IngredientMovement;
use App\Models\Order;
use App\Models\OrderRecipeSnapshot;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\StoreSession;
use App\Support\ActiveBranchContext;
use App\Support\RecipeCapacity;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create();
    $this->qave = Branch::factory()->create(['code' => 'QAVE', 'name' => 'Quezon Ave']);
});

test('only this Branch own Product stock tracking blocks its recipe; other Branches tracking it never do', function () {
    /** MAIN no longer tracks Coke, but QAVE and EAST still do: that no longer blocks a MAIN recipe. */
    BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->coke->id)->update(['tracks_inventory' => false]);
    $east = Branch::factory()->create(['code' => 'EAST', 'name' => 'East']);
    BranchProduct::factory()->for($this->qave)->for($this->ops->coke)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($east)->for($this->ops->coke)->create(['tracks_inventory' => true]);

    $page = fn () => $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->get(route('operations.recipes', ['plan' => $this->ops->drinks->id]));
    $page()->assertInertia(fn (Assert $page) => $page
        ->where('products.0.name', 'Coke Mismo')
        ->where('products.0.inventory_mode', 'recipe')
        ->where('products.0.tracks_product_stock', false)
        ->missing('products.0.tracked_branches'));

    $this->ops->recipe($this->ops->coke, null, ['water' => '10']);

    expect(Recipe::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->coke->id)->count())->toBe(1)
        ->and(BranchProduct::query()->where('product_id', $this->ops->coke->id)->where('tracks_inventory', true)->count())->toBe(2);

    /** MAIN tracking Product stock again is blocked by MAIN's own recipe only. */
    BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->coke->id)->update(['tracks_inventory' => true]);
    expect(fn () => $this->ops->recipe($this->ops->coke, null, ['water' => '20']))->toThrow(ValidationException::class, 'deducts Product stock at MAIN');
});

test('opening a blocking Branch settings switches through the existing Branch context and lands on that Product', function () {
    $target = route('products.index', ['search' => 'Coke Mismo', 'edit' => $this->ops->coke->id, 'section' => 'branch'], false);

    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->put(route('branch-context.update', $this->qave), ['redirect' => $target])
        ->assertRedirect($target)
        ->assertSessionHas(ActiveBranchContext::SESSION_KEY, $this->qave->id);
});

test('the Branch switch never follows an external or malformed return path', function (string $redirect) {
    $this->actingAs($this->ops->owner)
        ->put(route('branch-context.update', $this->qave), ['redirect' => $redirect])
        ->assertRedirectToRoute('workspace');
})->with(['https://evil.example/products', '//evil.example/products', '/\\evil.example', 'products', "/products\nSet-Cookie: x"]);

test('a Branch the user may not select stays forbidden even with a return path', function () {
    $this->actingAs($this->ops->cashier)
        ->put(route('branch-context.update', $this->qave), ['redirect' => '/workspaces/products'])
        ->assertForbidden();
});

test('a stale client cannot submit or sell a Size that still needs its recipe through any order path', function () {
    $session = CustomerQrSession::factory()->for($this->ops->branch)->create();
    $small = $this->ops->line($this->ops->lemonYakult, 1, 's');

    expect(fn () => app(SubmitCustomerQrOrder::class)->execute($this->ops->branch, $session, [
        'idempotency_key' => (string) Str::uuid(), 'order_type' => 'take_out', 'customer_label' => 'QR', 'items' => [$small],
    ]))->toThrow(ValidationException::class, 'Small Lemon Yakult needs a recipe')
        ->and(fn () => app(CreatePosDraftOrder::class)->execute($this->ops->cashier, $this->ops->branch, [
            'order_type' => 'take_out', 'customer_label' => 'Draft', 'items' => [$small],
        ]))->toThrow(ValidationException::class, 'Small Lemon Yakult needs a recipe')
        ->and(fn () => $this->ops->payNow([$small]))->toThrow(ValidationException::class, 'needs a recipe');

    $medium = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    expect(fn () => $this->ops->edit($medium, [$this->ops->line($this->ops->lemonYakult, 1, 'm'), $small]))
        ->toThrow(ValidationException::class, 'needs a recipe')
        ->and(Order::query()->whereNotNull('committed_at')->count())->toBe(1);
});

test('MAIN sells with its recipe while QAVE sells the same Product directly from Product stock, each at its own price', function () {
    $qaveCashier = $this->ops->user('cashier', $this->qave);
    StoreSession::factory()->for($this->qave)->create(['opened_by_user_id' => $qaveCashier->id]);
    BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->tapsilog->id)->update(['price_override' => '45.00']);
    BranchProduct::factory()->for($this->qave)->for($this->ops->tapsilog)->create(['tracks_inventory' => false, 'price_override' => '50.00']);

    /** QAVE has no Tapsilog recipe, so it may track Product stock while MAIN keeps its Ingredient recipe. */
    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->qave->id])
        ->put(route('products.branches.update', [$this->ops->tapsilog, $this->qave]), [
            'price_override' => '50.00', 'is_available' => true, 'tracks_inventory' => true, 'low_stock_threshold' => null,
        ])->assertSessionHasNoErrors();
    BranchInventory::factory()->for($this->qave)->for($this->ops->tapsilog)->create(['on_hand' => 5]);
    $this->ops->actAsOwnerOn($this->ops->branch);
    $this->ops->recipe($this->ops->tapsilog, null, ['rice' => '250', 'egg' => '1', 'water' => '100']);

    expect(app(RecipeCapacity::class)->catalog($this->ops->branch, [$this->ops->tapsilog->id])[$this->ops->tapsilog->id]['capacity'])->toBe(12)
        ->and(app(RecipeCapacity::class)->catalog($this->qave, [$this->ops->tapsilog->id])[$this->ops->tapsilog->id])->toBeNull();

    $main = $this->ops->payNow([$this->ops->line($this->ops->tapsilog, 1)]);
    $qave = app(PayNowOrder::class)->execute($qaveCashier, $this->qave, [
        'order_type' => 'take_out', 'customer_label' => 'QAVE', 'items' => [$this->ops->line($this->ops->tapsilog, 1)],
        'payment_method' => 'cash', 'cash_received' => '100.00', 'cashless_amount' => null, 'idempotency_key' => (string) Str::uuid(),
    ]);

    expect($main->total)->toBe('45.00')
        ->and($qave->total)->toBe('50.00')
        ->and($this->ops->stock('rice'))->toBe('2750')
        ->and(BranchInventory::query()->where('branch_id', $this->qave->id)->where('product_id', $this->ops->tapsilog->id)->value('on_hand'))->toBe(4)
        ->and(IngredientMovement::query()->where('branch_id', $this->qave->id)->exists())->toBeFalse()
        ->and(OrderRecipeSnapshot::query()->where('order_id', $qave->id)->sole()->recipe_state->value)->toBe('not_needed');
});

test('a QAVE recipe uses only QAVE ingredients and editing it never changes the MAIN recipe', function () {
    BranchProduct::factory()->for($this->qave)->for($this->ops->tapsilog)->create();
    $this->ops->actAsOwnerOn($this->qave);
    $plan = app(SaveOperationPlan::class)->execute($this->ops->owner, null, ['name' => 'Silog', 'icon' => 'meal', 'product_ids' => [$this->ops->tapsilog->id]]);
    $rice = $this->ops->ingredient('Rice', 'g', '5000', 'kg', '1000', '60.00', 'top_up', null, [$plan], '1000');

    /** MAIN's Rice is another Branch's Ingredient: a QAVE recipe cannot reference it. */
    expect(fn () => app(SaveRecipe::class)->execute($this->ops->owner, $this->ops->tapsilog, [
        'size_option_id' => null, 'lines' => [['ingredient_id' => $this->ops->ingredients['rice']->id, 'quantity' => '200']],
    ]))->toThrow(ValidationException::class, 'Use only active ingredients of QAVE');

    app(SaveRecipe::class)->execute($this->ops->owner, $this->ops->tapsilog, [
        'size_option_id' => null, 'lines' => [['ingredient_id' => $rice->id, 'quantity' => '300']],
    ]);

    $lines = fn (Branch $branch) => RecipeLine::query()->whereIn('recipe_id', Recipe::query()->where('branch_id', $branch->id)->where('product_id', $this->ops->tapsilog->id)->select('id'))
        ->get()->mapWithKeys(fn (RecipeLine $line): array => [$line->ingredient_id => (string) $line->quantity])->all();
    expect($lines($this->qave))->toBe([$rice->id => '300.0000'])
        ->and($lines($this->ops->branch))->toHaveCount(3)
        ->and($lines($this->ops->branch)[$this->ops->ingredients['rice']->id])->toBe('200.0000')
        ->and($rice->branch_id)->toBe($this->qave->id)
        ->and($rice->id)->not->toBe($this->ops->ingredients['rice']->id)
        ->and(app(RecipeCapacity::class)->catalog($this->qave, [$this->ops->tapsilog->id])[$this->ops->tapsilog->id]['capacity'])->toBe(3);
});
