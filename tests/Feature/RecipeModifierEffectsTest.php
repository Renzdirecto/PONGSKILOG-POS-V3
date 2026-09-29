<?php

use App\Actions\Operations\SetIngredientArchived;
use App\Models\AuditLog;
use App\Models\BranchProduct;
use App\Models\IngredientMovement;
use App\Models\Order;
use App\Models\OrderRecipeSnapshotModifier;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Support\ActiveBranchContext;
use App\Support\ExactQuantity;
use Illuminate\Validation\ValidationException;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create()->withAddOns();
});

/** @return array<string, string> ingredient name => net delta of the Order's Ingredient movements */
function effectNet(Order $order, ?string $type = null): array
{
    $net = [];
    IngredientMovement::query()->where('order_id', $order->id)->when($type, fn ($query) => $query->where('movement_type', $type))
        ->with('ingredient:id,name')->get()
        ->each(function (IngredientMovement $movement) use (&$net): void {
            $net[$movement->ingredient->name] = ($net[$movement->ingredient->name] ?? 0) + ExactQuantity::parse($movement->quantity_delta);
        });
    ksort($net);

    return array_map(fn (int $value): string => ExactQuantity::display($value), array_filter($net, fn (int $value): bool => $value !== 0));
}

test('an add-on effect is saved exactly per product and audited; no lines means no ingredient effect', function () {
    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->put(route('operations.recipes.effects.update', [$this->ops->lemonYakult, $this->ops->options['extra_yakult']]), [
            'lines' => [
                ['ingredient_id' => $this->ops->ingredients['yakult']->id, 'quantity' => '1'],
                ['ingredient_id' => $this->ops->ingredients['syrup']->id, 'quantity' => '7.5'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

    $effect = ProductModifierEffect::query()->where('product_id', $this->ops->lemonYakult->id)
        ->where('modifier_option_id', $this->ops->options['extra_yakult']->id)->with('lines')->sole();
    expect($effect->lines->map(fn ($line) => ExactQuantity::display(ExactQuantity::parse($line->quantity)))->sort()->values()->all())->toBe(['1', '7.5'])
        ->and(AuditLog::query()->where('action', 'recipe.modifier_effect_saved')->latest('id')->first()->after['lines'])->toHaveCount(2);

    $this->ops->effect($this->ops->lemonYakult, 'nata', []);
    expect(ProductModifierEffect::query()->where('modifier_option_id', $this->ops->options['nata']->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'recipe.modifier_effect_removed')->exists())->toBeTrue();
});

test('add-on effects only attach to active add-on options of the product and never to product stock products', function (string $case) {
    [$product, $option, $message] = match ($case) {
        'size option' => [$this->ops->lemonYakult, $this->ops->sizes['m'], 'Sizes use base recipes'],
        'unassigned product' => [$this->ops->tapsilog, $this->ops->options['nata'], 'Choose an active Add-on'],
        'product stock product' => [$this->ops->coke, $this->ops->options['nata'], 'deducts Product stock'],
    };
    if ($case === 'size option') {
        $this->ops->options['m'] = $option;
    }
    if ($case === 'product stock product') {
        $this->ops->coke->modifierGroups()->attach($this->ops->addOnGroup);
    }
    $key = $case === 'size option' ? 'm' : 'nata';

    expect(fn () => $this->ops->effect($product, $key, ['nata' => '10']))->toThrow(ValidationException::class, $message);
})->with(['size option', 'unassigned product', 'product stock product']);

test('an ingredient used by an add-on effect cannot be archived', function () {
    expect(fn () => app(SetIngredientArchived::class)->execute($this->ops->owner, $this->ops->ingredients['nata'], true))
        ->toThrow(ValidationException::class, 'add-on ingredient effect');
});

test('a sale consumes the base recipe plus each selected add-on effect; instructions and no-effect add-ons add nothing', function () {
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm', ['extra_yakult', 'nata', 'pearl', 'no_ice'])]);

    expect(effectNet($order))->toBe(['Lemon' => '-0.5', 'Nata' => '-30', 'Purified Water' => '-250', 'Syrup' => '-30', 'Yakult' => '-2'])
        ->and(IngredientMovement::query()->where('order_id', $order->id)->count())->toBe(5)
        ->and($this->ops->stock('yakult'))->toBe('8')
        ->and($this->ops->stock('nata'))->toBe('270');

    /** Every committed Add-on is snapshotted, including Pearl with no effect; Instructions never are. */
    $snapshotted = OrderRecipeSnapshotModifier::query()->withCount('lines')->get()->pluck('lines_count', 'option_name_snapshot')->all();
    expect($snapshotted)->toEqualCanonicalizing(['Extra Yakult' => 1, 'Nata' => 1, 'Pearl' => 0]);

    /** Yakult cost: base ₱11 + Extra Yakult ₱11 = ₱22; Nata ₱200/1000 g × 30 g = ₱6. */
    $costs = IngredientMovement::query()->where('order_id', $order->id)->with('ingredient:id,name')->get()
        ->mapWithKeys(fn (IngredientMovement $movement): array => [$movement->ingredient->name => $movement->estimated_cost_cents])->all();
    expect($costs['Yakult'])->toBe(2200)->and($costs['Nata'])->toBe(600);
});

test('an add-on ingredient with an unknown cost stays uncosted, never zero, and edits keep it unknown', function () {
    $this->ops->ingredients['cream'] = $this->ops->ingredient('Cream', 'ml', '500', null, null, null, 'none', null, [$this->ops->drinks], '200');
    $this->ops->effect($this->ops->lemonYakult, 'nata', ['nata' => '30', 'cream' => '10']);

    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 1, 'm', ['nata'])]);
    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 2, 'm', ['nata'])]);

    $costs = IngredientMovement::query()->where('order_id', $order->id)->with('ingredient:id,name')->get()
        ->groupBy(fn (IngredientMovement $movement): string => $movement->ingredient->name)
        ->map(fn ($movements) => $movements->pluck('estimated_cost_cents')->all());
    expect($costs['Cream'])->toBe([null, null])
        ->and($costs['Nata'])->toBe([600, 600])
        ->and(effectNet($order)['Cream'])->toBe('-20');
});

test('item quantity multiplies base and add-on usage and add-ons are counted per item', function () {
    $this->ops->effect($this->ops->lemonYakult, 'extra_yakult', ['yakult' => '1', 'syrup' => '5']);
    $order = $this->ops->payLater([
        $this->ops->line($this->ops->lemonYakult, 2, 'm', ['extra_yakult', 'nata']),
        $this->ops->line($this->ops->lemonYakult, 1, 'm'),
        $this->ops->line($this->ops->lemonYakult, 1, 'l', ['nata']),
    ]);

    /** Medium ×3 + Large ×1 base; Extra Yakult ×2 (yakult 1 + syrup 5 each); Nata ×3. */
    expect(effectNet($order))->toBe(['Lemon' => '-2.5', 'Nata' => '-90', 'Purified Water' => '-1100', 'Syrup' => '-145', 'Yakult' => '-6']);
    $count = IngredientMovement::query()->count();
    $this->ops->settle($order);
    expect(IngredientMovement::query()->count())->toBe($count);
});

test('a reusable add-on group never changes the stock of a product it has no effect on', function () {
    $juice = Product::factory()->create(['name' => 'Calamansi Juice', 'default_price' => '50.00']);
    BranchProduct::factory()->for($this->ops->branch)->for($juice)->create(['tracks_inventory' => false]);
    $juice->modifierGroups()->attach($this->ops->addOnGroup);
    $this->ops->recipe($juice, null, ['water' => '200']);

    $order = $this->ops->payNow([$this->ops->line($juice, 1, null, ['extra_yakult', 'nata'])]);

    expect(effectNet($order))->toBe(['Purified Water' => '-200'])->and($this->ops->stock('yakult'))->toBe('10');
});

test('a void restores the historical add-on effect even after the effect changed', function () {
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm', ['extra_yakult'])]);
    expect($this->ops->stock('yakult'))->toBe('8');

    $this->ops->effect($this->ops->lemonYakult, 'extra_yakult', ['yakult' => '2']);
    $this->ops->void($order);

    expect(effectNet($order, 'void_restoration'))->toMatchArray(['Yakult' => '2'])
        ->and(effectNet($order))->toBe([])
        ->and($this->ops->stock('yakult'))->toBe('10');
});

test('edits keep the historical add-on effect, including an add-on that had no effect at commitment', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 1, 'm', ['extra_yakult', 'pearl'])]);
    $this->ops->effect($this->ops->lemonYakult, 'extra_yakult', ['yakult' => '2']);
    $this->ops->effect($this->ops->lemonYakult, 'pearl', ['water' => '100']);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 2, 'm', ['extra_yakult', 'pearl'])]);

    expect(effectNet($order))->toBe(['Lemon' => '-1', 'Purified Water' => '-500', 'Syrup' => '-60', 'Yakult' => '-4']);
});

test('edits append only the ingredient delta when add-ons, sizes, quantities or instructions change', function () {
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $edits = fn () => effectNet($order, 'order_edit_adjustment');
    $base = ['Lemon' => '-0.5', 'Purified Water' => '-250', 'Syrup' => '-30', 'Yakult' => '-1'];

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'm', ['extra_yakult'])]);
    expect($edits())->toBe(['Yakult' => '-1'])->and(effectNet($order))->toBe([...$base, 'Yakult' => '-2']);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    expect($edits())->toBe([])->and(effectNet($order))->toBe($base);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'm', ['nata'])]);
    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'm', ['extra_yakult'])]);
    expect(effectNet($order))->toBe([...$base, 'Yakult' => '-2']);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'l', ['extra_yakult', 'nata'])]);
    expect(effectNet($order))->toBe(['Lemon' => '-1', 'Nata' => '-30', 'Purified Water' => '-350', 'Syrup' => '-45', 'Yakult' => '-2']);

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 3, 'l', ['extra_yakult', 'nata'])]);
    expect(effectNet($order))->toBe(['Lemon' => '-3', 'Nata' => '-90', 'Purified Water' => '-1050', 'Syrup' => '-135', 'Yakult' => '-6']);

    $count = IngredientMovement::query()->count();
    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 3, 'l', ['extra_yakult', 'nata', 'no_ice', 'less_sugar'])]);
    expect(IngredientMovement::query()->count())->toBe($count);

    $this->ops->void($order);
    expect(effectNet($order))->toBe([])
        ->and($this->ops->stock('yakult'))->toBe('10')
        ->and($this->ops->stock('nata'))->toBe('300')
        ->and($this->ops->stock('lemon'))->toBe('29.5');
});
