<?php

use App\Actions\Catalog\SyncProductModifierGroups;
use App\Actions\Catalog\UpdateModifierGroup;
use App\Actions\Operations\SaveRecipe;
use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\ProductSizes;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create()->withAddOns();
});

function secondSizeGroup(bool $active = true): ModifierGroup
{
    $group = ModifierGroup::factory()->create([
        'name' => 'Cup size', 'semantic_role' => ModifierSemanticRole::Size, 'selection_type' => ModifierSelectionType::Single,
        'min_select' => 1, 'max_select' => 1, 'is_active' => $active,
    ]);
    ModifierOption::factory()->create(['modifier_group_id' => $group->id, 'name' => 'Tall', 'price_delta' => '0.00']);

    return $group;
}

test('only the one size group defines base recipe variants; add-ons and instructions never do', function () {
    expect(array_column(app(ProductSizes::class)->forProduct($this->ops->lemonYakult), 'name'))->toBe(['Small', 'Medium', 'Large']);

    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->get(route('operations.recipes', ['plan' => $this->ops->drinks->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.0.name', 'Coke Mismo')
            ->where('products.1.name', 'Lemon Yakult')
            ->where('products.1.sizes.0.name', 'Small')
            ->where('products.1.sizes.2.name', 'Large')
            ->count('products.1.sizes', 3)
            ->where('products.1.add_ons.0.name', 'Nata')
            ->where('products.1.add_ons.1.name', 'Extra Yakult')
            ->where('products.1.add_ons.2.name', 'Pearl')
            ->count('products.1.add_ons', 3)
            ->where('products.1.instruction_groups', ['Instructions']));

    /** A size recipe keyed to an Add-on or Instruction option is refused. */
    foreach (['nata', 'no_ice'] as $key) {
        expect(fn () => app(SaveRecipe::class)->execute($this->ops->owner, $this->ops->lemonYakult, [
            'size_option_id' => $this->ops->options[$key]->id,
            'lines' => [['ingredient_id' => $this->ops->ingredients['lemon']->id, 'quantity' => '1']],
        ]))->toThrow(ValidationException::class);
    }
});

test('assigning a second active size group to a product is rejected on the server', function () {
    $cup = secondSizeGroup();

    expect(fn () => app(SyncProductModifierGroups::class)->execute($this->ops->owner, $this->ops->lemonYakult, [
        $this->ops->sizeGroup->id, $cup->id, $this->ops->addOnGroup->id,
    ]))->toThrow(ValidationException::class, 'can have only one active Size group');

    $this->actingAs($this->ops->owner)
        ->put(route('modifier-groups.products.update', $cup), ['product_ids' => [$this->ops->lemonYakult->id]])
        ->assertSessionHasErrors('modifier_group_ids');

    expect($this->ops->lemonYakult->modifierGroups()->pluck('modifier_groups.id')->sort()->values()->all())
        ->toBe(collect([$this->ops->sizeGroup->id, $this->ops->addOnGroup->id, $this->ops->instructionGroup->id])->sort()->values()->all());
});

test('existing assignments stay valid and an inactive second size group may be assigned', function () {
    $inactive = secondSizeGroup(active: false);

    app(SyncProductModifierGroups::class)->execute($this->ops->owner, $this->ops->lemonYakult, [
        $this->ops->sizeGroup->id, $this->ops->addOnGroup->id, $this->ops->instructionGroup->id, $inactive->id,
    ]);

    expect($this->ops->lemonYakult->modifierGroups()->count())->toBe(4)
        ->and(array_column(app(ProductSizes::class)->forProduct($this->ops->lemonYakult), 'name'))->toBe(['Small', 'Medium', 'Large']);

    /** Re-activating it, or turning an assigned Add-on group into a Size group, would give the product two. */
    foreach ([[$inactive, 'Cup size'], [$this->ops->addOnGroup, 'Add-ons']] as [$group, $name]) {
        expect(fn () => app(UpdateModifierGroup::class)->execute($this->ops->owner, $group, [
            'name' => $name, 'semantic_role' => 'size', 'selection_type' => 'single', 'min_select' => 1, 'max_select' => 1, 'is_active' => true,
        ]))->toThrow(ValidationException::class, 'Lemon Yakult already has an active Size group');
    }
    expect($this->ops->addOnGroup->fresh()->semantic_role)->toBeNull();

    /** A group assigned to no product with a size group may still become one. */
    $solo = ModifierGroup::factory()->create(['name' => 'Portion', 'semantic_role' => null]);
    $solo->products()->attach($this->ops->tapsilog);
    app(UpdateModifierGroup::class)->execute($this->ops->owner, $solo, [
        'name' => 'Portion', 'semantic_role' => 'size', 'selection_type' => 'single', 'min_select' => 1, 'max_select' => 1, 'is_active' => true,
    ]);
    expect($solo->fresh()->semantic_role)->toBe(ModifierSemanticRole::Size);
});

test('legacy data with two active size groups is a configuration error, never a guessed size', function () {
    $cup = secondSizeGroup();
    $this->ops->lemonYakult->modifierGroups()->attach($cup);

    $sizes = app(ProductSizes::class)->resolve([$this->ops->lemonYakult->id]);
    expect($sizes['sizes'][$this->ops->lemonYakult->id])->toBe([])
        ->and($sizes['conflicts'][$this->ops->lemonYakult->id])->toBe(['Cup size', 'Size']);

    expect(fn () => $this->ops->recipe($this->ops->lemonYakult, 'm', ['lemon' => '1']))
        ->toThrow(ValidationException::class, 'more than one active Size group');

    $product = collect(app(BranchCatalog::class)->browse($this->ops->branch, true)['products'])->firstWhere('id', $this->ops->lemonYakult->id);
    expect($product['is_available'])->toBeFalse()
        ->and($product['availability_reason'])->toBe('recipe_required')
        ->and($product['recipe']['state'])->toBe('configuration_error');

    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->get(route('operations.recipes', ['plan' => $this->ops->drinks->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.1.state', 'configuration_error')
            ->where('products.1.size_conflict', ['Cup size', 'Size']));

    $line = $this->ops->line($this->ops->lemonYakult, 1, 'm');
    $line['modifiers'][] = ['group_id' => $cup->id, 'option_id' => $cup->options()->value('id')];
    expect(fn () => $this->ops->payNow([$line]))->toThrow(ValidationException::class, 'more than one active Size group');
    expect(Order::query()->whereNotNull('committed_at')->count())->toBe(0);
});

test('instruction groups stay optional, multiple and price-neutral and never carry ingredient effects', function () {
    $group = $this->ops->instructionGroup->fresh();
    expect($group->min_select)->toBe(0)
        ->and($group->selection_type)->toBe(ModifierSelectionType::Multiple)
        ->and($group->options()->where('price_delta', '!=', 0)->exists())->toBeFalse();

    expect(fn () => $this->ops->effect($this->ops->lemonYakult, 'no_ice', ['water' => '50']))
        ->toThrow(ValidationException::class, 'Instructions never use ingredients');

    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm', ['no_ice', 'less_sugar'])]);
    expect($order->total)->toBe('70.00');
});
