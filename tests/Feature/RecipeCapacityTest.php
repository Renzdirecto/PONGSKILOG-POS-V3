<?php

use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\CustomerQrAccess;
use App\Support\CustomerQrProjection;
use App\Support\RecipeCapacity;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create()->withAddOns();
});

/** @return array<string, mixed> the catalog row of one Product */
function catalogRow(OperationsScenario $ops, string $productId): array
{
    return collect(app(BranchCatalog::class)->browse($ops->branch, true)['products'])->firstWhere('id', $productId);
}

/** @param list<string> $options */
function focusOn(OperationsScenario $ops, ?string $size, array $options = []): array
{
    $line = $ops->line($ops->lemonYakult, 1, $size, $options);

    return ['product_id' => $line['product_id'], 'modifiers' => $line['modifiers']];
}

test('the catalog shows each size capacity from branch ingredient stock and never invents a count for a size without recipe', function () {
    $this->ops->setStock('yakult', '30');
    $row = catalogRow($this->ops, $this->ops->lemonYakult->id);

    /** Medium: lemon 59, yakult 30, syrup 33, water 32 → 30. Large: lemon 29, yakult 30, syrup 22, water 22 → 22. */
    expect($row['is_available'])->toBeTrue()
        ->and($row['stock_status'])->toBe('in_stock')
        ->and($row['recipe']['state'])->toBe('available')
        ->and($row['recipe']['capacity'])->toBe(30)
        ->and(collect($row['recipe']['sizes'])->map(fn (array $size): array => [$size['name'], $size['state'], $size['capacity']])->all())->toBe([
            ['Small', 'recipe_required', null],
            ['Medium', 'available', 30],
            ['Large', 'available', 22],
        ]);

    /** A Product without sizes uses its Regular recipe: rice 3000/200, egg 30/1, water 8000/100 → 15. */
    $tapsilog = catalogRow($this->ops, $this->ops->tapsilog->id);
    expect($tapsilog['recipe']['sizes'])->toBe([['key' => 'base', 'option_id' => null, 'name' => 'Regular', 'state' => 'available', 'capacity' => 15]]);
});

test('a recipe product is out of stock only when no size can be made, after the existing availability gates', function () {
    $this->ops->setStock('lemon', '0.5');
    $row = catalogRow($this->ops, $this->ops->lemonYakult->id);
    expect($row['is_available'])->toBeTrue()
        ->and(collect($row['recipe']['sizes'])->pluck('capacity', 'name')->all())->toBe(['Small' => null, 'Medium' => 1, 'Large' => 0]);

    $this->ops->setStock('lemon', '0');
    $row = catalogRow($this->ops, $this->ops->lemonYakult->id);
    expect($row['is_available'])->toBeFalse()
        ->and($row['availability_reason'])->toBe('out_of_stock')
        ->and($row['stock_status'])->toBe('out_of_stock')
        ->and($row['recipe']['state'])->toBe('out_of_stock');

    $this->ops->lemonYakult->update(['is_active' => false]);
    expect(catalogRow($this->ops, $this->ops->lemonYakult->id)['availability_reason'])->toBe('product_disabled');
});

test('a shared ingredient limits every product using it; negative and missing balances give zero servings', function () {
    $this->ops->setStock('water', '-100');

    foreach ([$this->ops->lemonYakult, $this->ops->tapsilog] as $product) {
        $row = catalogRow($this->ops, $product->id);
        expect($row['is_available'])->toBeFalse()->and($row['recipe']['capacity'])->toBe(0);
    }

    $this->ops->setStock('water', '8000');
    $this->ops->ingredients['ice'] = $this->ops->ingredient('Ice', 'g', '1000', null, null, null, 'none', null, [$this->ops->silog]);
    $this->ops->recipe($this->ops->tapsilog, null, ['rice' => '200', 'ice' => '50']);
    expect(catalogRow($this->ops, $this->ops->tapsilog->id)['recipe']['capacity'])->toBe(0);
});

test('products outside recipe management keep their existing availability', function () {
    $tea = $this->ops->legacyDrink();
    BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $tea->id)->update(['no_recipe_needed' => false]);

    $coke = catalogRow($this->ops, $this->ops->coke->id);
    expect($coke['recipe'])->toBeNull()->and($coke['tracks_inventory'])->toBeTrue()->and($coke['on_hand'])->toBe(20);

    BranchInventory::query()->where('product_id', $this->ops->coke->id)->update(['on_hand' => 0]);
    expect(catalogRow($this->ops, $this->ops->coke->id)['availability_reason'])->toBe('out_of_stock');

    $row = catalogRow($this->ops, $tea->id);
    expect($row['recipe'])->toBeNull()->and($row['is_available'])->toBeTrue()->and($row['stock_status'])->toBe('not_tracked');
});

test('the selected configuration counts only the selected add-ons and the rest of the cart', function () {
    $this->ops->setStock('yakult', '5');
    $capacity = app(RecipeCapacity::class);

    $base = $capacity->configuration($this->ops->branch, [], focusOn($this->ops, 'm'));
    expect($base['capacity'])->toBe(5)
        ->and($base['options'])->toMatchArray([
            $this->ops->sizes['s']->id => 0,
            $this->ops->sizes['m']->id => 5,
            $this->ops->sizes['l']->id => 5,
            $this->ops->options['extra_yakult']->id => 2,
            $this->ops->options['nata']->id => 5,
        ])
        ->and($base['options'])->not->toHaveKeys([$this->ops->options['pearl']->id, $this->ops->options['no_ice']->id]);

    expect($capacity->configuration($this->ops->branch, [], focusOn($this->ops, 'm', ['extra_yakult']))['capacity'])->toBe(2)
        ->and($capacity->configuration($this->ops->branch, [], focusOn($this->ops, 'm', ['no_ice', 'less_sugar', 'pearl']))['capacity'])->toBe(5);

    $cart = [$this->ops->line($this->ops->lemonYakult, 3, 'l'), $this->ops->line($this->ops->tapsilog, 1)];
    $withCart = $capacity->configuration($this->ops->branch, $cart, focusOn($this->ops, 'm', ['extra_yakult']));
    expect($withCart['capacity'])->toBe(1)
        ->and($capacity->configuration($this->ops->branch, $cart, focusOn($this->ops, 'm'))['capacity'])->toBe(2);

    expect($capacity->configuration($this->ops->branch, [], focusOn($this->ops, 's')))->toMatchArray(['state' => 'recipe_required', 'capacity' => null]);
});

test('an add-on that cannot be fulfilled is unavailable while the base drink stays sellable', function () {
    $this->ops->setStock('yakult', '1');

    $result = app(RecipeCapacity::class)->configuration($this->ops->branch, [], focusOn($this->ops, 'm'));
    expect($result['state'])->toBe('available')
        ->and($result['capacity'])->toBe(1)
        ->and($result['options'][$this->ops->options['extra_yakult']->id])->toBe(0)
        ->and(catalogRow($this->ops, $this->ops->lemonYakult->id)['is_available'])->toBeTrue();
});

test('the pos capacity endpoint returns counts to cashiers of the branch only', function () {
    $this->ops->setStock('yakult', '5');
    $payload = ['lines' => [$this->ops->line($this->ops->lemonYakult, 1, 'm')], 'focus' => focusOn($this->ops, 'm', ['extra_yakult'])];

    $this->actingAs($this->ops->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->postJson(route('pos.recipe-capacity'), $payload)
        ->assertOk()
        ->assertJson(['limited' => true, 'state' => 'available', 'capacity' => 2])
        ->assertJsonPath('options.'.$this->ops->options['extra_yakult']->id, 2);

    $kitchen = $this->ops->user('kitchen_staff', $this->ops->branch);
    $this->actingAs($kitchen)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->postJson(route('pos.recipe-capacity'), $payload)->assertForbidden();
});

test('customer qr sees whether a configuration fits, never the branch serving counts', function () {
    $this->ops->setStock('yakult', '3');
    $token = bin2hex(random_bytes(32));
    CustomerQrSession::factory()->for($this->ops->branch)->create(['token_hash' => hash('sha256', $token)]);
    $request = fn (int $quantity) => $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($this->ops->branch), $token)
        ->postJson(route('qr.recipe-capacity', $this->ops->branch), [
            'lines' => [], 'focus' => [...focusOn($this->ops, 'm', ['extra_yakult']), 'quantity' => $quantity],
        ]);

    /** Without the customer's QR session cookie nothing is disclosed. */
    $this->postJson(route('qr.recipe-capacity', $this->ops->branch), ['lines' => [], 'focus' => focusOn($this->ops, 'm')])->assertStatus(419);

    $request(1)->assertOk()->assertJson(['limited' => true, 'fits' => true])
        ->assertJsonMissingPath('capacity')
        ->assertJsonPath('options.'.$this->ops->options['extra_yakult']->id, true);
    $request(2)->assertOk()->assertJson(['fits' => false]);

    $product = collect(app(CustomerQrProjection::class)->catalog($this->ops->branch)['products'])->firstWhere('id', $this->ops->lemonYakult->id);
    expect($product['recipe']['capacity'])->toBeNull()
        ->and(collect($product['recipe']['sizes'])->pluck('capacity')->filter()->all())->toBe([])
        ->and(collect($product['recipe']['sizes'])->pluck('state', 'name')->all())->toBe(['Small' => 'recipe_required', 'Medium' => 'available', 'Large' => 'available']);
});
