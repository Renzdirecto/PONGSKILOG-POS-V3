<?php

use App\Models\BranchInventory;
use App\Models\BranchProduct;
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
