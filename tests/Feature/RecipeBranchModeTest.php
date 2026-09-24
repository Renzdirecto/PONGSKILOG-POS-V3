<?php

use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
use App\Models\Order;
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

test('the recipe blocker names every Branch that still tracks Product stock, whatever Branch is selected', function () {
    /** MAIN no longer tracks Coke, but QAVE and EAST still do. */
    BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->coke->id)->update(['tracks_inventory' => false]);
    $east = Branch::factory()->create(['code' => 'EAST', 'name' => 'East']);
    BranchProduct::factory()->for($this->qave)->for($this->ops->coke)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($east)->for($this->ops->coke)->create(['tracks_inventory' => true]);

    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id])
        ->get(route('operations.recipes', ['plan' => $this->ops->drinks->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.0.name', 'Coke Mismo')
            ->where('products.0.inventory_mode', 'product_stock')
            ->where('products.0.tracked_at', ['EAST', 'QAVE'])
            ->where('products.0.tracked_branches', [
                ['id' => $east->id, 'code' => 'EAST', 'name' => 'East'],
                ['id' => $this->qave->id, 'code' => 'QAVE', 'name' => 'Quezon Ave'],
            ]));

    expect(fn () => $this->ops->recipe($this->ops->coke, null, ['water' => '10']))->toThrow(ValidationException::class);
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

test('one shared recipe sells at each Branch price from that Branch stock, and no Branch may track Product stock', function () {
    $qaveCashier = $this->ops->user('cashier', $this->qave);
    StoreSession::factory()->for($this->qave)->create(['opened_by_user_id' => $qaveCashier->id]);
    BranchProduct::query()->where('branch_id', $this->ops->branch->id)->where('product_id', $this->ops->tapsilog->id)->update(['price_override' => '45.00']);
    BranchProduct::factory()->for($this->qave)->for($this->ops->tapsilog)->create(['tracks_inventory' => false, 'price_override' => '50.00']);
    $this->ops->actAsOwnerOn($this->qave);
    foreach (['rice' => '400', 'egg' => '2', 'water' => '1000'] as $key => $count) {
        app(AdjustIngredientStock::class)->execute($this->ops->owner, $this->ops->ingredients[$key], [
            'mode' => 'count', 'quantity' => $count, 'reason' => 'Opening count', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /** Same recipe (rice 200, egg 1, water 100), different Branch stock, so different capacity. */
    expect(app(RecipeCapacity::class)->catalog($this->ops->branch, [$this->ops->tapsilog->id])[$this->ops->tapsilog->id]['capacity'])->toBe(15)
        ->and(app(RecipeCapacity::class)->catalog($this->qave, [$this->ops->tapsilog->id])[$this->ops->tapsilog->id]['capacity'])->toBe(2);

    $main = $this->ops->payNow([$this->ops->line($this->ops->tapsilog, 1)]);
    $qave = app(PayNowOrder::class)->execute($qaveCashier, $this->qave, [
        'order_type' => 'take_out', 'customer_label' => 'QAVE', 'items' => [$this->ops->line($this->ops->tapsilog, 1)],
        'payment_method' => 'cash', 'cash_received' => '100.00', 'cashless_amount' => null, 'idempotency_key' => (string) Str::uuid(),
    ]);

    expect($main->total)->toBe('45.00')
        ->and($qave->total)->toBe('50.00')
        ->and($this->ops->stock('rice'))->toBe('2800')
        ->and($this->ops->stock('rice', $this->qave))->toBe('200')
        ->and($this->ops->stock('egg', $this->qave))->toBe('1')
        ->and(BranchInventory::query()->where('product_id', $this->ops->tapsilog->id)->exists())->toBeFalse();

    /** Recipe mode is global: QAVE cannot start deducting Product stock for it. */
    $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->qave->id])
        ->put(route('products.branches.update', [$this->ops->tapsilog, $this->qave]), [
            'price_override' => '50.00', 'is_available' => true, 'tracks_inventory' => true, 'low_stock_threshold' => null,
        ])->assertSessionHasErrors('tracks_inventory');
    expect(BranchProduct::query()->where('branch_id', $this->qave->id)->where('product_id', $this->ops->tapsilog->id)->value('tracks_inventory'))->toBeFalse();
});
