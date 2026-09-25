<?php

use App\Actions\Operations\SaveOperationPlan;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\TransitionKitchenOrder;
use App\Enums\KitchenStatus;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\IngredientMovement;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderRecipeSnapshot;
use App\Support\ExactQuantity;
use App\Support\OperationsSummary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create();
});

/** @return array<string, string> ingredient name => net delta for the Order, e.g. ['Lemon' => '-0.5'] */
function orderIngredientNet(Order $order): array
{
    $net = [];
    IngredientMovement::query()->where('order_id', $order->id)->with('ingredient:id,name')->get()
        ->each(function (IngredientMovement $movement) use (&$net): void {
            $net[$movement->ingredient->name] = ($net[$movement->ingredient->name] ?? 0) + ExactQuantity::parse($movement->quantity_delta);
        });
    ksort($net);

    return array_map(fn (int $value): string => ExactQuantity::display($value), array_filter($net, fn (int $value): bool => $value !== 0));
}

test('pay now consumes the recipe of each sold size exactly once with exact fractional quantities', function () {
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);

    expect($this->ops->stock('lemon'))->toBe('29')
        ->and($this->ops->stock('yakult'))->toBe('9')
        ->and($this->ops->stock('syrup'))->toBe('970')
        ->and($this->ops->stock('water'))->toBe('7750')
        ->and(IngredientMovement::query()->where('order_id', $order->id)->where('movement_type', 'sale_consumption')->count())->toBe(4);

    $snapshot = OrderRecipeSnapshot::query()->where('order_id', $order->id)->sole();
    expect($snapshot->recipe_state->value)->toBe('recipe')
        ->and($snapshot->operation_plan_id)->toBe($this->ops->drinks->id)
        ->and($snapshot->size_name_snapshot)->toBe('Medium');

    /** Lemon ₱10/pc × 0.5 = ₱5, Yakult ₱55/5 pcs × 1 = ₱11, Syrup ₱150/1000 ml × 30 = ₱4.50, Water ₱40/5000 ml × 250 = ₱2. */
    $costs = IngredientMovement::query()->where('order_id', $order->id)->with('ingredient:id,name')->get()
        ->mapWithKeys(fn (IngredientMovement $movement): array => [$movement->ingredient->name => $movement->estimated_cost_cents])->all();
    expect($costs)->toMatchArray(['Lemon' => 500, 'Yakult' => 1100, 'Syrup' => 450, 'Purified Water' => 200]);
});

test('pay later consumes once and settlement never consumes again', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 2, 'm')]);
    expect(orderIngredientNet($order))->toBe(['Lemon' => '-1', 'Purified Water' => '-500', 'Syrup' => '-60', 'Yakult' => '-2']);
    $count = IngredientMovement::query()->count();

    $this->ops->settle($order);

    expect(IngredientMovement::query()->count())->toBe($count)
        ->and($this->ops->stock('lemon'))->toBe('28.5');
});

test('a sale can use the last recipe ingredient stock exactly but never drive it below zero', function () {
    /** Yakult 10 pcs limits Medium (1 pc each) to 10 servings; the rule supersedes the earlier negative-stock sales. */
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 10, 'm')]);
    expect($this->ops->stock('yakult'))->toBe('0')->and($this->ops->stock('lemon'))->toBe('24.5');

    expect(fn () => $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]))
        ->toThrow(ValidationException::class, 'Not enough ingredient stock for Lemon Yakult');
    expect($this->ops->stock('yakult'))->toBe('0')->and($this->ops->stock('lemon'))->toBe('24.5');
});

test('a product that never had a recipe still sells without inventing ingredient usage and is not costed', function () {
    $tea = $this->ops->legacyDrink();
    $order = $this->ops->payNow([$this->ops->line($tea, 1)]);

    expect($order->payment_status->value)->toBe('paid')
        ->and(IngredientMovement::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and(OrderRecipeSnapshot::query()->where('order_id', $order->id)->sole()->recipe_state->value)->toBe('missing');

    $summary = app(OperationsSummary::class)->today($this->ops->branch);
    expect($summary['plans'][$this->ops->drinks->id]['uncosted_sales_cents'])->toBe(4000)
        ->and($summary['plans'][$this->ops->drinks->id]['incomplete'])->toBeTrue();
});

test('a recipe-backed product cannot sell a size that has no recipe yet', function () {
    expect(fn () => $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 's')]))
        ->toThrow(ValidationException::class, 'Small Lemon Yakult needs a recipe before it can be sold');
    expect(Order::query()->whereNotNull('committed_at')->count())->toBe(0);
});

test('direct resale uses product stock only and recipe products never decrement product stock', function () {
    $order = $this->ops->payNow([
        $this->ops->line($this->ops->coke, 2),
        $this->ops->line($this->ops->lemonYakult, 1, 'm'),
    ]);

    expect(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(18)
        ->and(IngredientMovement::query()->where('order_id', $order->id)->where('ingredient_id', '!=', null)->count())->toBe(4)
        ->and(OrderRecipeSnapshot::query()->where('order_id', $order->id)->where('product_id', $this->ops->coke->id)->sole()->recipe_state->value)->toBe('not_needed')
        ->and(BranchInventory::query()->where('product_id', $this->ops->lemonYakult->id)->exists())->toBeFalse();
});

test('an edit appends only the compensating delta for decreases, increases, replacements and size changes', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 2, 'm')]);
    $sale = IngredientMovement::query()->where('order_id', $order->id)->pluck('id')->all();

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $edit = IngredientMovement::query()->where('order_id', $order->id)->where('movement_type', 'order_edit_adjustment')->with('ingredient:id,name')->get()
        ->mapWithKeys(fn (IngredientMovement $movement): array => [$movement->ingredient->name => ExactQuantity::display(ExactQuantity::parse($movement->quantity_delta))])->all();
    expect($edit)->toMatchArray(['Lemon' => '0.5', 'Yakult' => '1', 'Syrup' => '30', 'Purified Water' => '250'])
        ->and(IngredientMovement::query()->whereKey($sale)->count())->toBe(count($sale))
        ->and($this->ops->stock('lemon'))->toBe('29');

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 3, 'm')]);
    expect(orderIngredientNet($order))->toBe(['Lemon' => '-1.5', 'Purified Water' => '-750', 'Syrup' => '-90', 'Yakult' => '-3']);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'l')]);
    expect(orderIngredientNet($order))->toBe(['Lemon' => '-1', 'Purified Water' => '-350', 'Syrup' => '-45', 'Yakult' => '-1']);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->tapsilog, 1)]);
    expect(orderIngredientNet($order))->toBe(['Egg' => '-1', 'Purified Water' => '-100', 'Rice' => '-200'])
        ->and($this->ops->stock('lemon'))->toBe('29.5')
        ->and($this->ops->stock('water'))->toBe('7900');
});

test('an edit keeps a recipe-sold product on ingredients after its branch switches it to product stock', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->tapsilog, 1)]);
    BranchProduct::query()->where('product_id', $this->ops->tapsilog->id)->update(['tracks_inventory' => true]);
    BranchInventory::factory()->for($this->ops->branch)->for($this->ops->tapsilog)->create(['on_hand' => 10]);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->tapsilog, 3)]);
    expect(orderIngredientNet($order))->toBe(['Egg' => '-3', 'Purified Water' => '-300', 'Rice' => '-600'])
        ->and(BranchInventory::query()->where('product_id', $this->ops->tapsilog->id)->value('on_hand'))->toBe(10);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->tapsilog, 1)]);
    expect(orderIngredientNet($order))->toBe(['Egg' => '-1', 'Purified Water' => '-100', 'Rice' => '-200'])
        ->and(BranchInventory::query()->where('product_id', $this->ops->tapsilog->id)->value('on_hand'))->toBe(10)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

test('an edit never silently drops product stock sold from a product whose tracking was turned off', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->coke, 2), $this->ops->line($this->ops->tapsilog, 1)]);
    BranchProduct::query()->where('product_id', $this->ops->coke->id)->update(['tracks_inventory' => false]);

    expect(fn () => $this->ops->edit($order, [$this->ops->line($this->ops->coke, 4), $this->ops->line($this->ops->tapsilog, 1)]))
        ->toThrow(ValidationException::class, 'Coke Mismo was sold from Product stock');
    expect(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(18)
        ->and($order->refresh()->version)->toBe($order->version);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->coke, 2), $this->ops->line($this->ops->tapsilog, 2)]);
    expect(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(18)
        ->and(orderIngredientNet($order))->toBe(['Egg' => '-2', 'Purified Water' => '-200', 'Rice' => '-400']);
});

test('recipe-backed to a product without a recipe and back only moves the recipe-backed part', function () {
    $tea = $this->ops->legacyDrink();
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $order = $this->ops->edit($order, [$this->ops->line($tea, 1)]);
    expect(orderIngredientNet($order))->toBe([]);

    $order = $this->ops->edit($order, [$this->ops->line($tea, 1), $this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    expect(orderIngredientNet($order))->toBe(['Lemon' => '-0.5', 'Purified Water' => '-250', 'Syrup' => '-30', 'Yakult' => '-1']);
});

test('an edit cannot add a size of a recipe-backed product that has no recipe', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);

    expect(fn () => $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 's')]))
        ->toThrow(ValidationException::class, 'needs a recipe');
    expect(orderIngredientNet($order))->toBe(['Lemon' => '-0.5', 'Purified Water' => '-250', 'Syrup' => '-30', 'Yakult' => '-1']);
});

test('an edit retry with the same key appends no second delta', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 2, 'm')]);
    $key = (string) Str::uuid();
    $input = $this->ops->editInput($order, [$this->ops->line($this->ops->lemonYakult, 1, 'm')], $key);
    app(EditCommittedOrder::class)->execute($this->ops->cashier, $this->ops->branch, $order->fresh(), $input);
    $count = IngredientMovement::query()->count();

    app(EditCommittedOrder::class)->execute($this->ops->cashier, $this->ops->branch, $order->fresh(), $input);

    expect(IngredientMovement::query()->count())->toBe($count)->and($this->ops->stock('lemon'))->toBe('29');
});

test('a void restores the historical recipe quantities even after the recipe changed', function () {
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $this->ops->recipe($this->ops->lemonYakult, 'm', ['lemon' => '1', 'yakult' => '2']);

    $this->ops->void($order);

    $restored = IngredientMovement::query()->where('order_id', $order->id)->where('movement_type', 'void_restoration')->with('ingredient:id,name')->get()
        ->mapWithKeys(fn (IngredientMovement $movement): array => [$movement->ingredient->name => ExactQuantity::display(ExactQuantity::parse($movement->quantity_delta))])->all();
    expect($restored)->toMatchArray(['Lemon' => '0.5', 'Yakult' => '1', 'Syrup' => '30', 'Purified Water' => '250'])
        ->and(orderIngredientNet($order))->toBe([])
        ->and($this->ops->stock('lemon'))->toBe('29.5')
        ->and($this->ops->stock('yakult'))->toBe('10');
});

test('a void restores the post-edit net consumption exactly once, even when retried', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 2, 'm')]);
    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $key = (string) Str::uuid();
    $version = $order->fresh()->version;

    $this->ops->void($order, $key, $version);
    $count = IngredientMovement::query()->count();
    $this->ops->void($order, $key, $version);

    expect(IngredientMovement::query()->where('order_id', $order->id)->where('movement_type', 'void_restoration')->count())->toBe(4)
        ->and(IngredientMovement::query()->count())->toBe($count)
        ->and($this->ops->stock('lemon'))->toBe('29.5')
        ->and($this->ops->stock('syrup'))->toBe('1000');
});

test('the database refuses a second sale or void restoration row for one snapshot line', function (string $type) {
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $movement = IngredientMovement::query()->where('order_id', $order->id)->firstOrFail();
    $attributes = [
        'branch_id' => $movement->branch_id, 'ingredient_id' => $movement->ingredient_id, 'movement_type' => $type,
        'quantity_delta' => '1.0000', 'balance_after' => '1.0000', 'order_id' => $order->id,
        'order_recipe_snapshot_id' => $movement->order_recipe_snapshot_id,
    ];
    if ($type === 'void_restoration') {
        IngredientMovement::query()->create($attributes);
    }

    expect(fn () => IngredientMovement::query()->create($attributes))->toThrow(UniqueConstraintViolationException::class);
})->with(['sale_consumption', 'void_restoration']);

test('kitchen transitions, settlement and payment events never move ingredient stock', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $count = IngredientMovement::query()->count();
    $kitchen = $this->ops->user('kitchen_staff', $this->ops->branch);

    foreach ([KitchenStatus::Preparing, KitchenStatus::Ready, KitchenStatus::Done] as $status) {
        app(TransitionKitchenOrder::class)->execute($kitchen, $this->ops->branch, $order->fresh(), $status);
    }
    $this->ops->settle($order);

    expect(IngredientMovement::query()->count())->toBe($count);
});

test('historical estimated cost and plan attribution never move when costs or plan membership change', function () {
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $before = app(OperationsSummary::class)->today($this->ops->branch)['plans'][$this->ops->drinks->id];

    $this->ops->ingredients['yakult']->update(['purchase_unit_cost' => '100.00']);
    app(SaveOperationPlan::class)->execute($this->ops->owner, $this->ops->silog, [
        'name' => 'Silog', 'icon' => 'meal', 'product_ids' => [$this->ops->tapsilog->id, $this->ops->lemonYakult->id],
    ]);
    $after = app(OperationsSummary::class)->today($this->ops->branch);

    expect($before['cogs_cents'])->toBe(2250)
        ->and($after['plans'][$this->ops->drinks->id]['cogs_cents'])->toBe(2250)
        ->and($after['plans'][$this->ops->drinks->id]['sales_cents'])->toBe(7000)
        ->and($after['plans'][$this->ops->silog->id]['sales_cents'] ?? 0)->toBe(0);

    $next = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    expect(OrderRecipeSnapshot::query()->where('order_id', $next->id)->sole()->operation_plan_id)->toBe($this->ops->silog->id)
        ->and(IngredientMovement::query()->where('order_id', $next->id)->whereHas('ingredient', fn ($query) => $query->where('name', 'Yakult'))->value('estimated_cost_cents'))->toBe(2000)
        ->and(OrderRecipeSnapshot::query()->where('order_id', $order->id)->sole()->operation_plan_id)->toBe($this->ops->drinks->id);
});
