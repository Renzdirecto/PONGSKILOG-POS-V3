<?php

use App\Actions\Operations\ConfirmPamamalengke;
use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Enums\StoreSessionStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\PamamalengkeListEntry;
use App\Models\PamamalengkePurchase;
use App\Models\StoreSessionExpense;
use App\Support\ActiveBranchContext;
use App\Support\ExactQuantity;
use App\Support\OperationsSummary;
use App\Support\ReplenishmentAdvisor;
use App\Support\StoreSessionReconciliation;
use App\Support\StoreSessionSalesReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create();
});

function recommend(Ingredient $ingredient, string $current): array
{
    return app(ReplenishmentAdvisor::class)->recommend($ingredient, ExactQuantity::fromInput(ltrim($current, '-')) * (str_starts_with($current, '-') ? -1 : 1));
}

function confirmRun(object $test, array $items, array $overrides = [])
{
    return $test->actingAs($test->ops->owner)
        ->withSession([ActiveBranchContext::SESSION_KEY => $test->ops->branch->id])
        ->post(route('operations.pamamalengke.confirm', $overrides['plan'] ?? $test->ops->drinks), [
            'idempotency_key' => $overrides['key'] ?? (string) Str::uuid(),
            'payment_source' => $overrides['source'] ?? 'cash',
            'note' => $overrides['note'] ?? null,
            'items' => $items,
        ]);
}

test('top up to target buys whole purchase units for any shortfall, including fractions and negative stock', function () {
    $lemon = $this->ops->ingredients['lemon']->fresh();

    expect(recommend($lemon, '29.5'))->toMatchArray(['kind' => 'buy', 'units' => 1, 'base_quantity' => 10000, 'estimate_cents' => 1000])
        ->and(recommend($lemon, '30')['kind'])->toBe('ok')
        ->and(recommend($lemon, '31')['reason'])->toBe('Above target.')
        ->and(recommend($lemon, '-0.25')['units'])->toBe(31);

    $water = $this->ops->ingredients['water']->fresh();
    expect(recommend($water, '9999.5')['units'])->toBe(1)
        ->and(recommend($water, '4999')['units'])->toBe(2);
});

test('reorder at threshold waits for the reorder point and then buys back to target, at least one unit', function () {
    $yakult = $this->ops->ingredients['yakult']->fresh();

    expect(recommend($yakult, '4')['kind'])->toBe('hold')
        ->and(recommend($yakult, '2'))->toMatchArray(['kind' => 'buy', 'units' => 1, 'base_quantity' => 50000, 'estimate_cents' => 5500])
        ->and(recommend($yakult, '0')['reason'])->toStartWith('Out of stock.')
        ->and(recommend($yakult, '-6')['units'])->toBe(3)
        ->and(recommend($yakult, '5')['kind'])->toBe('ok');
});

test('no automatic suggestion and a missing purchase unit never recommend a buy; unknown cost is not zero', function () {
    $manual = $this->ops->ingredient('Straws', 'pc', '100', 'pack', '50', '45.00', 'none', null, [$this->ops->drinks]);
    $setup = $this->ops->ingredient('Mint', 'g', '100', null, null, null, 'none', null, [$this->ops->drinks]);
    $unknown = $this->ops->ingredient('Pandan', 'pc', '10', 'bundle', '5', null, 'top_up', null, [$this->ops->drinks]);

    expect(recommend($manual, '0')['kind'])->toBe('manual')
        ->and(recommend($setup, '0')['kind'])->toBe('setup')
        ->and(recommend($unknown, '0'))->toMatchArray(['kind' => 'buy', 'units' => 2, 'estimate_cents' => null]);
});

test('the pamamalengke page is server computed and shows a shared ingredient once per plan list', function () {
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 8, 'm')]);

    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->get(route('operations.pamamalengke', ['plan' => $this->ops->drinks->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/pamamalengke')
            ->where('market.auto', fn ($auto) => collect($auto)->pluck('ingredient_id')->sort()->values()->all() === collect([
                $this->ops->ingredients['lemon']->id, $this->ops->ingredients['yakult']->id, $this->ops->ingredients['water']->id,
            ])->sort()->values()->all())
            ->where('market.estimate_cents', 5500 + 5 * 1000 + 4000)
            ->where('ingredients', fn ($rows) => collect($rows)->where('name', 'Purified Water')->count() === 1));
});

test('confirming writes one canonical store purchase and exact ingredient restocks; manual items never restock', function () {
    $entry = PamamalengkeListEntry::query()->create([
        'branch_id' => $this->ops->branch->id, 'operation_plan_id' => $this->ops->drinks->id, 'entry_type' => 'manual',
        'name' => 'Ice', 'quantity' => '2.0000', 'unit' => 'bag', 'estimated_unit_cost' => '30.00', 'created_by_user_id' => $this->ops->owner->id,
    ]);

    confirmRun($this, [
        ['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['yakult']->id, 'actual_quantity' => '2', 'actual_unit_cost' => '56.50'],
        ['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['syrup']->id, 'actual_quantity' => '0.5', 'actual_unit_cost' => '150.00'],
        ['type' => 'manual', 'entry_id' => $entry->id, 'name' => 'Ice', 'unit' => 'bag', 'actual_quantity' => '3', 'actual_unit_cost' => '30.00', 'note' => 'Stall 4'],
    ])->assertRedirect(route('operations.pamamalengke', ['plan' => $this->ops->drinks->id]))->assertSessionHasNoErrors();

    $expense = StoreSessionExpense::query()->sole();
    $purchase = PamamalengkePurchase::query()->sole();
    expect($expense->amount)->toBe('278.00')
        ->and($expense->payment_source)->toBe('cash')
        ->and($expense->store_session_id)->toBe($this->ops->session->id)
        ->and($expense->created_by_user_id)->toBe($this->ops->owner->id)
        ->and($purchase->store_session_expense_id)->toBe($expense->id)
        ->and($purchase->items()->count())->toBe(3)
        ->and($this->ops->stock('yakult'))->toBe('20')
        ->and($this->ops->stock('syrup'))->toBe('1500')
        ->and(IngredientMovement::query()->where('movement_type', 'purchase_restock')->count())->toBe(2)
        ->and(IngredientMovement::query()->where('movement_type', 'purchase_restock')->pluck('store_session_expense_id')->unique()->all())->toBe([$expense->id])
        ->and($purchase->items()->where('line_type', 'manual')->sole()->ingredient_movement_id)->toBeNull()
        ->and(PamamalengkeListEntry::query()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'store_expense_recorded')->where('auditable_id', $expense->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'pamamalengke.confirmed')->where('auditable_id', $purchase->id)->exists())->toBeTrue();

    $expected = app(StoreSessionReconciliation::class)->flows([$this->ops->session->id => $this->ops->branch->id])[$this->ops->session->id];
    expect($expected['expenses']['cash'])->toBe(27800);
});

test('recommended and actual quantities may differ and are both kept', function () {
    confirmRun($this, [
        ['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['lemon']->id, 'actual_quantity' => '3', 'actual_unit_cost' => '9.50'],
    ])->assertSessionHasNoErrors();

    $item = PamamalengkePurchase::query()->sole()->items()->sole();
    expect($item->recommended_quantity)->toBe('1.0000')
        ->and($item->actual_quantity)->toBe('3.0000')
        ->and($item->base_quantity)->toBe('3.0000')
        ->and($item->estimated_unit_cost)->toBe('10.00')
        ->and($item->actual_unit_cost)->toBe('9.50')
        ->and($this->ops->stock('lemon'))->toBe('32.5');
});

test('a retried confirmation never duplicates the purchase, expense or restock; a changed payload is rejected', function () {
    $key = (string) Str::uuid();
    $items = [['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['lemon']->id, 'actual_quantity' => '1', 'actual_unit_cost' => '10.00']];

    confirmRun($this, $items, ['key' => $key])->assertSessionHasNoErrors();
    confirmRun($this, $items, ['key' => $key])->assertSessionHasNoErrors();
    confirmRun($this, [[...$items[0], 'actual_quantity' => '2']], ['key' => $key])->assertStatus(409);

    expect(PamamalengkePurchase::query()->count())->toBe(1)
        ->and(StoreSessionExpense::query()->count())->toBe(1)
        ->and(IngredientMovement::query()->where('movement_type', 'purchase_restock')->count())->toBe(1)
        ->and($this->ops->stock('lemon'))->toBe('30.5');
});

test('confirming keeps the existing open store session rule and needs a positive total and known costs', function () {
    $items = [['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['lemon']->id, 'actual_quantity' => '1', 'actual_unit_cost' => '10.00']];
    confirmRun($this, [[...$items[0], 'actual_unit_cost' => '0.00']])->assertSessionHasErrors('items');
    confirmRun($this, [[...$items[0], 'actual_unit_cost' => '']])->assertSessionHasErrors('items.0.actual_unit_cost');

    $this->ops->session->forceFill(['status' => StoreSessionStatus::Closed, 'closed_at' => now()])->saveQuietly();
    confirmRun($this, $items)->assertSessionHasErrors('store');

    expect(StoreSessionExpense::query()->count())->toBe(0)
        ->and(IngredientMovement::query()->where('movement_type', 'purchase_restock')->exists())->toBeFalse()
        ->and($this->ops->stock('lemon'))->toBe('29.5');
});

test('a shared ingredient bought from either plan restocks the one branch record', function () {
    confirmRun($this, [['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['water']->id, 'actual_quantity' => '1', 'actual_unit_cost' => '40.00']])->assertSessionHasNoErrors();
    confirmRun($this, [['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['water']->id, 'actual_quantity' => '1', 'actual_unit_cost' => '40.00']], ['plan' => $this->ops->silog])->assertSessionHasNoErrors();

    expect(BranchIngredientStock::query()->where('ingredient_id', $this->ops->ingredients['water']->id)->count())->toBe(1)
        ->and($this->ops->stock('water'))->toBe('18000');
});

test('the latest purchase cost drives future estimates while past sales keep their cost snapshot', function () {
    $before = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    confirmRun($this, [['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['yakult']->id, 'actual_quantity' => '1', 'actual_unit_cost' => '65.00']])->assertSessionHasNoErrors();
    $after = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $yakultCost = fn ($order) => IngredientMovement::query()->where('order_id', $order->id)->where('ingredient_id', $this->ops->ingredients['yakult']->id)->value('estimated_cost_cents');

    expect($this->ops->ingredients['yakult']->fresh()->purchase_unit_cost)->toBe('65.00')
        ->and($yakultCost($before))->toBe(1100)
        ->and($yakultCost($after))->toBe(1300)
        ->and(recommend($this->ops->ingredients['yakult']->fresh(), '0')['estimate_cents'])->toBe(6500)
        ->and(AuditLog::query()->where('action', 'pamamalengke.confirmed')->sole()->metadata['cost_updates'][0]['to'])->toBe('65.00');
});

test('cash after purchases is not profit and store-wide expenses are counted once, only for the business', function () {
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 2, 'm'), $this->ops->line($this->ops->tapsilog, 1), $this->ops->line($this->ops->coke, 1)]);
    confirmRun($this, [
        ['type' => 'ingredient', 'ingredient_id' => $this->ops->ingredients['lemon']->id, 'actual_quantity' => '2', 'actual_unit_cost' => '10.00'],
        ['type' => 'manual', 'name' => 'Ice', 'unit' => 'bag', 'actual_quantity' => '1', 'actual_unit_cost' => '30.00'],
    ])->assertSessionHasNoErrors();
    app(RecordStoreSessionExpense::class)->execute($this->ops->cashier, $this->ops->branch, [
        'idempotency_key' => (string) Str::uuid(), 'description' => 'LPG top-up', 'amount' => '350.00', 'payment_source' => 'cash', 'restock' => false,
    ]);

    $summary = app(OperationsSummary::class)->today($this->ops->branch);
    $drinks = $summary['plans'][$this->ops->drinks->id];
    $silog = $summary['plans'][$this->ops->silog->id];
    $business = $summary['business'];

    /** Drinks: 2 × ₱70 Lemon Yakult + ₱25 Coke = ₱165; COGS = 2 × ₱22.50; Coke is direct resale and uncosted. */
    expect($drinks['sales_cents'])->toBe(16500)
        ->and($drinks['cogs_cents'])->toBe(4500)
        ->and($drinks['uncosted_sales_cents'])->toBe(2500)
        ->and($drinks['incomplete'])->toBeTrue()
        ->and($drinks['pamamalengke_cents'])->toBe(5000)
        ->and($drinks['non_stock_cents'])->toBe(3000)
        ->and($drinks['other_expenses_cents'])->toBe(0)
        ->and($drinks['cash_after_cents'])->toBe(11500)
        ->and($drinks['operating_profit_cents'])->toBe(16500 - 4500 - 3000);

    /** Tapsilog: Rice 200 g × ₱54/kg = ₱10.80, Egg ₱8, Water 100 ml × ₱40/5 L = ₱0.80. */
    expect($silog['sales_cents'])->toBe(12000)->and($silog['cogs_cents'])->toBe(1960);

    expect($business['sales_cents'])->toBe(28500)
        ->and($business['cogs_cents'])->toBe(6460)
        ->and($business['pamamalengke_cents'])->toBe(5000)
        ->and($business['other_expenses_cents'])->toBe(35000)
        ->and($business['cash_after_cents'])->toBe(28500 - 5000 - 35000)
        ->and($business['operating_profit_cents'])->toBe(28500 - 6460 - 3000 - 35000);
});

test('operations business net sales match the Phase 16 reports and voided orders drop out', function () {
    $kept = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $voided = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'l')]);
    $this->ops->void($voided);

    $summary = app(OperationsSummary::class)->today($this->ops->branch);
    $report = app(StoreSessionSalesReport::class)->for($this->ops->branch, ['date' => 'today']);

    expect($summary['business']['sales_cents'])->toBe(7000)
        ->and($summary['business']['cogs_cents'])->toBe(2250)
        ->and($report['summary']['net_sales'])->toBe('70.00');
});

test('the confirm action cannot be reached through a cashier or with another branch plan list entry', function () {
    expect(fn () => app(ConfirmPamamalengke::class)->execute($this->ops->cashier, $this->ops->drinks, []))
        ->toThrow(AuthorizationException::class);

    $entry = PamamalengkeListEntry::query()->create([
        'branch_id' => Branch::factory()->create()->id, 'operation_plan_id' => $this->ops->drinks->id, 'entry_type' => 'manual',
        'name' => 'Ice', 'quantity' => '1.0000', 'unit' => 'bag', 'created_by_user_id' => $this->ops->owner->id,
    ]);
    confirmRun($this, [['type' => 'manual', 'entry_id' => $entry->id, 'name' => 'Ice', 'unit' => 'bag', 'actual_quantity' => '1', 'actual_unit_cost' => '30.00']])
        ->assertSessionHasErrors('items');
    expect(StoreSessionExpense::query()->count())->toBe(0);
});
