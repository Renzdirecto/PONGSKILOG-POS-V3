<?php

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Support\InventoryState;
use Illuminate\Support\Facades\DB;

test('inventory state uses branch tracking and inclusive stock thresholds without querying or writing', function (
    ?bool $tracked,
    ?int $onHand,
    ?int $threshold,
    ?int $expectedOnHand,
    string $status,
) {
    $configuration = $tracked === null ? null : new BranchProduct(['tracks_inventory' => $tracked, 'low_stock_threshold' => $threshold]);
    $balance = $onHand === null ? null : new BranchInventory(['on_hand' => $onHand]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $state = app(InventoryState::class)->resolve($configuration, $balance);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($state)->toBe([
        'tracked' => $tracked === true,
        'on_hand' => $expectedOnHand,
        'low_stock_threshold' => $tracked === true ? $threshold : null,
        'status' => $status,
    ]);
    expect($queries)->toBeEmpty();
})->with([
    'unconfigured' => [null, null, null, null, 'not_tracked'],
    'untracked without balance' => [false, null, null, null, 'not_tracked'],
    'untracked ignores retained balance' => [false, 10, null, null, 'not_tracked'],
    'tracked without balance' => [true, null, 5, 0, 'out_of_stock'],
    'zero balance' => [true, 0, 5, 0, 'out_of_stock'],
    'negative balance state' => [true, -1, 5, -1, 'out_of_stock'],
    'no threshold' => [true, 1, null, 1, 'in_stock'],
    'below threshold' => [true, 1, 5, 1, 'low_stock'],
    'at threshold' => [true, 5, 5, 5, 'low_stock'],
    'above threshold' => [true, 6, 5, 6, 'in_stock'],
    'zero threshold with stock' => [true, 1, 0, 1, 'in_stock'],
]);

test('grouped branch attention counts match the per branch stock filters exactly', function () {
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $empty = Branch::factory()->create(['code' => 'EMPTY']);
    $stocked = function (Branch $branch, ?int $onHand, ?int $threshold, bool $tracked = true, bool $active = true): void {
        $product = Product::factory()->create(['is_active' => $active]);
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => $tracked, 'low_stock_threshold' => $threshold]);
        if ($onHand !== null) {
            BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $onHand]);
        }
    };
    $stocked($main, null, 5);
    $stocked($main, 0, null);
    $stocked($main, 3, 5);
    $stocked($main, 5, 5);
    $stocked($main, 6, 5);
    $stocked($main, 0, 5, tracked: false);
    $stocked($main, 0, 5, active: false);
    $stocked($qave, 1, null);
    $stocked($qave, 0, null);

    $state = app(InventoryState::class);
    $grouped = $state->attentionCountsByBranch([$main->id, $qave->id, $empty->id]);
    foreach ([$main, $qave, $empty] as $branch) {
        $count = function (string $status) use ($state, $branch): int {
            $query = Product::query()->where('products.is_active', true);
            $state->filterProducts($query, $branch, $status);

            return $query->count('products.id');
        };
        expect([$grouped[$branch->id]['out_of_stock'] ?? 0, $grouped[$branch->id]['low_stock'] ?? 0])
            ->toBe([$count('out_of_stock'), $count('low_stock')]);
    }
    expect($grouped[$main->id])->toBe(['out_of_stock' => 2, 'low_stock' => 2])
        ->and($grouped[$qave->id])->toBe(['out_of_stock' => 1, 'low_stock' => 0])
        ->and($grouped)->not->toHaveKey($empty->id);
});
