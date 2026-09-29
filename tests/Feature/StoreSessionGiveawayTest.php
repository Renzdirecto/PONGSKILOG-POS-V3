<?php

use App\Enums\IngredientMovementType;
use App\Enums\InventoryMovementType;
use App\Events\IngredientStockChanged;
use App\Events\ReportsChanged;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\IngredientMovement;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\StoreSessionGiveaway;
use App\Models\StoreSessionGiveawayReversal;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CurrentStoreSessionExpenses;
use App\Support\OperationsSummary;
use App\Support\StoreSessionReconciliation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create()->withAddOns();
});

/**
 * @param  list<string>  $options  Add-on / Instruction option keys of the scenario
 * @param  array<string, mixed>  $overrides
 */
function giveaway(OperationsScenario $ops, string $product, ?string $size, int $quantity, array $options = [], array $overrides = [], ?User $user = null): TestResponse
{
    $line = $ops->line($ops->{$product}, $quantity, $size, $options);

    return test()->actingAs($user ?? $ops->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $ops->branch->id])
        ->postJson(route('store-session-giveaways.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'product_id' => $line['product_id'],
            'quantity' => $line['quantity'],
            'modifiers' => $line['modifiers'],
            'reason_code' => 'complimentary',
            'note' => null,
            ...$overrides,
        ]);
}

function reverseGiveaway(OperationsScenario $ops, string $giveawayId, ?string $key = null, string $reason = 'Recorded the wrong drink', ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? $ops->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $ops->branch->id])
        ->postJson(route('store-session-giveaways.reverse', $giveawayId), [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'reason' => $reason,
        ]);
}

/** @return array<string, string> */
function giveawayStock(OperationsScenario $ops): array
{
    return collect(['lemon', 'yakult', 'syrup', 'water', 'nata', 'rice', 'egg'])->mapWithKeys(fn (string $key): array => [$key => $ops->stock($key)])->all();
}

/** @return array{orders: int, payments: int, expenses: int} */
function commercialCounts(): array
{
    return ['orders' => Order::query()->count(), 'payments' => Payment::query()->count(), 'expenses' => StoreSessionExpense::query()->count()];
}

test('a Medium recipe giveaway with Add-ons and an Instruction deducts base recipe plus Add-on effects exactly, with no sale', function () {
    Event::fake([IngredientStockChanged::class, ReportsChanged::class]);
    $before = giveawayStock($this->ops);
    $commercial = commercialCounts();

    $response = giveaway($this->ops, 'lemonYakult', 'm', 2, ['nata', 'extra_yakult', 'no_ice'])->assertOk()
        ->assertJsonPath('giveaway.product_name', 'Lemon Yakult')
        ->assertJsonPath('giveaway.size_name', 'Medium')
        ->assertJsonPath('giveaway.add_ons', ['Nata', 'Extra Yakult'])
        ->assertJsonPath('giveaway.instructions', ['No ice'])
        ->assertJsonPath('giveaway.stock_mode', 'recipe')
        ->assertJsonPath('giveaway.reversal', null);

    /** Medium base (lemon 0.5, yakult 1, syrup 30, water 250) + Extra Yakult 1 + Nata 30 g, all × 2; No ice adds nothing. */
    expect(giveawayStock($this->ops))->toBe([
        ...$before,
        'lemon' => '28.5', 'yakult' => '6', 'syrup' => '940', 'water' => '7500', 'nata' => '240',
    ])->and(collect($response->json('giveaway.stock_effects'))->pluck('quantity', 'name')->sortKeys()->all())->toBe([
        'Lemon' => '1', 'Nata' => '60', 'Purified Water' => '500', 'Syrup' => '60', 'Yakult' => '4',
    ]);

    $giveaway = StoreSessionGiveaway::query()->sole();
    expect(commercialCounts())->toBe($commercial)
        ->and($giveaway->store_session_id)->toBe($this->ops->session->id)
        ->and($giveaway->created_by_user_id)->toBe($this->ops->cashier->id)
        ->and($giveaway->inventory_movement_id)->toBeNull()
        ->and(collect($giveaway->selections)->pluck('semantic_role', 'option_name')->all())->toBe(['Medium' => 'size', 'Nata' => null, 'Extra Yakult' => null, 'No ice' => 'instruction'])
        ->and(collect($giveaway->stock_basis['base'])->pluck('quantity', 'name')->sortKeys()->all())->toBe(['Lemon' => '0.5', 'Purified Water' => '250', 'Syrup' => '30', 'Yakult' => '1'])
        ->and(collect($giveaway->stock_basis['add_ons'])->pluck('option_name')->all())->toBe(['Nata', 'Extra Yakult'])
        ->and(IngredientMovement::query()->where('store_session_giveaway_id', $giveaway->id)->pluck('movement_type')->unique()->all())->toBe([IngredientMovementType::Giveaway])
        ->and(IngredientMovement::query()->whereNotNull('store_session_giveaway_id')->whereNotNull('order_id')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'store_session.giveaway_recorded')->sole()->metadata['ingredient_movements'])->toHaveCount(5);
    Event::assertDispatched(IngredientStockChanged::class);
    Event::assertDispatched(ReportsChanged::class);
});

test('each Size uses its own base recipe and a Product without sizes uses its Regular recipe', function (string $product, ?string $size, array $expected) {
    $before = giveawayStock($this->ops);

    giveaway($this->ops, $product, $size, 1)->assertOk();

    expect(giveawayStock($this->ops))->toBe([...$before, ...$expected]);
})->with([
    'Large' => ['lemonYakult', 'l', ['lemon' => '28.5', 'yakult' => '9', 'syrup' => '955', 'water' => '7650']],
    'Medium' => ['lemonYakult', 'm', ['lemon' => '29', 'yakult' => '9', 'syrup' => '970', 'water' => '7750']],
    'Regular (no Size group)' => ['tapsilog', null, ['rice' => '2800', 'egg' => '29', 'water' => '7900']],
]);

test('a Size without a recipe on a recipe-backed Product cannot be given away', function () {
    $before = giveawayStock($this->ops);

    giveaway($this->ops, 'lemonYakult', 's', 1)->assertUnprocessable()
        ->assertJsonValidationErrors(['product_id' => 'Small Lemon Yakult needs a recipe']);

    expect(giveawayStock($this->ops))->toBe($before)
        ->and(StoreSessionGiveaway::query()->exists())->toBeFalse();
});

test('a direct-resale Product deducts only its Product stock, never Ingredients', function () {
    giveaway($this->ops, 'coke', null, 3)->assertOk()
        ->assertJsonPath('giveaway.stock_mode', 'product_stock')
        ->assertJsonPath('giveaway.stock_effects.0.quantity', '3');

    $giveaway = StoreSessionGiveaway::query()->sole();
    expect(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(17)
        ->and(InventoryMovement::query()->whereKey($giveaway->inventory_movement_id)->sole()->movement_type)->toBe(InventoryMovementType::Giveaway)
        ->and(IngredientMovement::query()->where('store_session_giveaway_id', $giveaway->id)->exists())->toBeFalse();
});

test('a giveaway never drives stock below zero', function (string $case) {
    $before = giveawayStock($this->ops);
    match ($case) {
        'insufficient Ingredient' => (function () {
            $this->ops->setStock('yakult', '1');
            giveaway($this->ops, 'lemonYakult', 'm', 2)->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        })(),
        'insufficient Add-on Ingredient' => (function () {
            $this->ops->setStock('nata', '20');
            giveaway($this->ops, 'lemonYakult', 'm', 1, ['nata'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        })(),
        'insufficient Product stock' => giveaway($this->ops, 'coke', null, 21)->assertUnprocessable()->assertJsonValidationErrors(['quantity']),
    };

    expect(StoreSessionGiveaway::query()->exists())->toBeFalse()
        ->and(IngredientMovement::query()->whereNotNull('store_session_giveaway_id')->exists())->toBeFalse()
        ->and(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(20)
        ->and(array_filter(giveawayStock($this->ops), fn (string $value): bool => str_starts_with($value, '-')))->toBe([]);
    if ($case === 'insufficient Product stock') {
        expect(giveawayStock($this->ops))->toBe($before);
    }
})->with(['insufficient Ingredient', 'insufficient Add-on Ingredient', 'insufficient Product stock']);

test('the canonical customization rules apply to giveaways', function (string $case, string $field) {
    $overrides = match ($case) {
        'unavailable Product' => (function () {
            BranchProduct::query()->where('product_id', $this->ops->lemonYakult->id)->update(['is_available' => false]);

            return [];
        })(),
        'missing required Size' => ['modifiers' => []],
        'option of another Product' => ['modifiers' => [['group_id' => $this->ops->sizeGroup->id, 'option_id' => $this->ops->sizes['m']->id], ['group_id' => Str::uuid()->toString(), 'option_id' => Str::uuid()->toString()]]],
        'other reason without a note' => ['reason_code' => 'other'],
    };

    giveaway($this->ops, 'lemonYakult', 'm', 1, [], $overrides)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(StoreSessionGiveaway::query()->exists())->toBeFalse();
})->with([
    'unavailable Product' => ['unavailable Product', 'product_id'],
    'missing required Size' => ['missing required Size', 'modifiers'],
    'option of another Product' => ['option of another Product', 'modifiers'],
    'other reason without a note' => ['other reason without a note', 'note'],
]);

test('a closed Store Session rejects giveaways', function () {
    $this->ops->session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->ops->cashier->id]);

    giveaway($this->ops, 'coke', null, 1)->assertUnprocessable()->assertJsonValidationErrors(['store']);

    expect(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(20);
});

test('only active Store Session operators of the selected Branch may record giveaways', function (string $case, int $status) {
    $request = match ($case) {
        'guest' => fn () => $this->postJson(route('store-session-giveaways.store'), []),
        'owner' => fn () => giveaway($this->ops, 'coke', null, 1, [], [], $this->ops->owner),
        'kitchen' => fn () => giveaway($this->ops, 'coke', null, 1, [], [], $this->ops->user('kitchen_staff', $this->ops->branch)),
        'inactive cashier' => fn () => giveaway($this->ops, 'coke', null, 1, [], [], tap($this->ops->user('cashier', $this->ops->branch), fn (User $user) => $user->forceFill(['is_active' => false])->save())),
        /** The branch comes from the server context: a cashier of another branch resolves to their own, closed branch. */
        'other branch cashier' => fn () => giveaway($this->ops, 'coke', null, 1, [], [], $this->ops->user('cashier', Branch::factory()->create())),
    };

    $request()->assertStatus($status);

    expect(StoreSessionGiveaway::query()->exists())->toBeFalse()
        ->and(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(20);
})->with([
    'guest' => ['guest', 401],
    'owner' => ['owner', 403],
    'kitchen' => ['kitchen', 403],
    'inactive cashier' => ['inactive cashier', 401],
    'other branch cashier' => ['other branch cashier', 422],
]);

test('a Product outside this Branch assortment cannot be given away here, even if another Branch sells it', function () {
    $other = Branch::factory()->create();
    BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->coke->id)->delete();
    BranchProduct::factory()->for($other)->for($this->ops->coke)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($other)->for($this->ops->coke)->create(['on_hand' => 9]);

    giveaway($this->ops, 'coke', null, 1)->assertUnprocessable()->assertJsonValidationErrors('product_id');

    expect(StoreSessionGiveaway::query()->count())->toBe(0)
        ->and(BranchInventory::query()->where('branch_id', $other->id)->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(9)
        ->and(BranchInventory::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(20);
});

test('a duplicate submit is idempotent and a changed payload under the same key conflicts', function () {
    $key = (string) Str::uuid();
    $first = giveaway($this->ops, 'lemonYakult', 'm', 1, ['nata'], ['idempotency_key' => $key])->assertOk();
    $again = giveaway($this->ops, 'lemonYakult', 'm', 1, ['nata'], ['idempotency_key' => $key])->assertOk();

    expect($again->json('giveaway.id'))->toBe($first->json('giveaway.id'))
        ->and(StoreSessionGiveaway::query()->count())->toBe(1)
        ->and(IngredientMovement::query()->whereNotNull('store_session_giveaway_id')->count())->toBe(5)
        ->and($this->ops->stock('nata'))->toBe('270');
    giveaway($this->ops, 'lemonYakult', 'm', 2, ['nata'], ['idempotency_key' => $key])->assertConflict();
    expect($this->ops->stock('nata'))->toBe('270');
});

test('a reversal restores exactly the historical deduction once, even after the recipe and Add-on effect change', function () {
    $before = giveawayStock($this->ops);
    $commercial = commercialCounts();
    $id = giveaway($this->ops, 'lemonYakult', 'm', 1, ['extra_yakult'])->assertOk()->json('giveaway.id');
    expect($this->ops->stock('yakult'))->toBe('8');

    /** Tomorrow Extra Yakult becomes +2 and Medium uses 1 lemon; the old giveaway still restores +1 and 0.5. */
    $this->ops->effect($this->ops->lemonYakult, 'extra_yakult', ['yakult' => '2']);
    $this->ops->recipe($this->ops->lemonYakult, 'm', ['lemon' => '1', 'yakult' => '1', 'syrup' => '30', 'water' => '250']);
    $key = (string) Str::uuid();
    reverseGiveaway($this->ops, $id, $key)->assertOk()->assertJsonPath('giveaway.reversal.reason', 'Recorded the wrong drink');
    reverseGiveaway($this->ops, $id, $key)->assertOk();
    reverseGiveaway($this->ops, $id)->assertUnprocessable()->assertJsonValidationErrors(['giveaway' => 'already been reversed']);

    expect(giveawayStock($this->ops))->toBe($before)
        ->and(StoreSessionGiveawayReversal::query()->count())->toBe(1)
        ->and(IngredientMovement::query()->where('movement_type', IngredientMovementType::GiveawayReversal)->count())->toBe(4)
        ->and(StoreSessionGiveaway::query()->whereKey($id)->exists())->toBeTrue()
        ->and(commercialCounts())->toBe($commercial)
        ->and(AuditLog::query()->where('action', 'store_session.giveaway_reversed')->count())->toBe(1);
});

test('a Product stock giveaway reversal restores its Product stock once', function () {
    $id = giveaway($this->ops, 'coke', null, 2)->assertOk()->json('giveaway.id');
    reverseGiveaway($this->ops, $id)->assertOk();

    expect(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(20)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovementType::GiveawayReversal)->sole()->quantity_delta)->toBe(2);
});

test('a giveaway of another Branch or an earlier Store Session cannot be reversed here', function () {
    $id = giveaway($this->ops, 'coke', null, 1)->assertOk()->json('giveaway.id');
    $other = Branch::factory()->create();
    $otherCashier = $this->ops->user('cashier', $other);
    StoreSession::factory()->for($other)->create(['opened_by_user_id' => $otherCashier->id]);

    $this->actingAs($otherCashier)->withSession([ActiveBranchContext::SESSION_KEY => $other->id])
        ->postJson(route('store-session-giveaways.reverse', $id), ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Wrong'])
        ->assertNotFound();

    $this->ops->session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->ops->cashier->id]);
    StoreSession::factory()->for($this->ops->branch)->create(['opened_by_user_id' => $this->ops->cashier->id]);
    reverseGiveaway($this->ops, $id)->assertUnprocessable()->assertJsonValidationErrors(['giveaway' => 'own Store Session']);

    expect(BranchInventory::query()->where('product_id', $this->ops->coke->id)->value('on_hand'))->toBe(19);
});

test('giveaways are never sales, payments, expenses or COGS and are reported separately', function () {
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $summary = app(OperationsSummary::class)->today($this->ops->branch);
    $expected = app(StoreSessionReconciliation::class)->calculate($this->ops->branch, $this->ops->session->fresh());

    giveaway($this->ops, 'lemonYakult', 'm', 2, ['nata'])->assertOk();
    giveaway($this->ops, 'coke', null, 1)->assertOk();
    $reversed = giveaway($this->ops, 'tapsilog', null, 1)->assertOk()->json('giveaway.id');
    reverseGiveaway($this->ops, $reversed)->assertOk();

    $after = app(OperationsSummary::class)->today($this->ops->branch);
    expect($after['business']['sales_cents'])->toBe($summary['business']['sales_cents'])
        ->and($after['business']['orders'])->toBe($summary['business']['orders'])
        ->and($after['business']['cogs_cents'])->toBe($summary['business']['cogs_cents'])
        ->and($after['business']['other_expenses_cents'])->toBe($summary['business']['other_expenses_cents'])
        ->and($after['giveaways']['count'])->toBe(2)
        ->and($after['giveaways']['items'])->toBe(3)
        ->and($after['giveaways']['uncosted'])->toBe(1)
        /** 2 × (lemon 0.5 @ ₱10 + yakult 1 @ ₱11 + syrup 30 ml @ ₱0.15 + water 250 ml @ ₱0.008 + nata 30 g @ ₱0.20) */
        ->and($after['giveaways']['cost_cents'])->toBe(2 * (500 + 1100 + 450 + 200 + 600))
        ->and(app(StoreSessionReconciliation::class)->calculate($this->ops->branch, $this->ops->session->fresh()))->toBe($expected);

    $session = app(CurrentStoreSessionExpenses::class)->for($this->ops->branch, $this->ops->session);
    expect($session['expense_totals']['total'])->toBe('0.00')
        ->and($session['giveaway_count'])->toBe(3)
        ->and(collect($session['giveaways'])->whereNotNull('reversal')->count())->toBe(1);
});

test('the giveaway catalog is the canonical customization catalog of the selected Branch', function () {
    $this->actingAs($this->ops->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->getJson(route('store-session-giveaways.catalog'))->assertOk()
        ->assertJsonPath('products.0.id', fn (string $id): bool => Str::isUuid($id))
        ->assertJson(fn ($json) => $json->has('categories')->has('products')->etc());

    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->getJson(route('store-session-giveaways.catalog'))->assertForbidden();
});
