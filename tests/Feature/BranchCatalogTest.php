<?php

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreSession;
use App\Support\BranchCatalog;

test('effective prices use only the requested branch override and otherwise the global price', function () {
    $product = Product::factory()->create(['default_price' => '95.00']);
    $main = Branch::factory()->create();
    $qave = Branch::factory()->create();
    $catalog = app(BranchCatalog::class);

    expect($catalog->effectivePrice($product, $main))->toBe('95.00');
    BranchProduct::factory()->for($product)->for($main)->create(['price_override' => '99.00']);
    $product->load('branchProducts');
    expect($catalog->effectivePrice($product, $main))->toBe('99.00');
    expect($catalog->effectivePrice($product, $qave))->toBe('95.00');

    $override = BranchProduct::factory()->for($product)->for($qave)->create(['price_override' => null]);
    expect($catalog->effectivePrice($product, $qave))->toBe('95.00');
    $override->update(['price_override' => '0.00']);
    expect($catalog->effectivePrice($product, $qave))->toBe('0.00');
    expect($catalog->effectivePrice($product, $main))->toBe('99.00');
});

test('effective pricing reads current persisted prices instead of stale loaded relations', function () {
    $product = Product::factory()->create(['default_price' => '95.00']);
    $branch = Branch::factory()->create();
    $override = BranchProduct::factory()->for($product)->for($branch)->create(['price_override' => '99.00']);
    $product->load('branchProducts');
    $catalog = app(BranchCatalog::class);

    $override->update(['price_override' => null]);
    Product::query()->whereKey($product->id)->update(['default_price' => '100.25']);

    expect($catalog->effectivePrice($product, $branch))->toBe('100.25');
});

test('availability requires an active product and category and no branch disablement', function (
    bool $categoryActive,
    bool $productActive,
    ?bool $overrideAvailable,
    bool $expected,
) {
    $category = Category::factory()->create(['is_active' => $categoryActive]);
    $product = Product::factory()->for($category)->create(['is_active' => $productActive]);
    $branch = Branch::factory()->create();
    if ($overrideAvailable !== null) {
        BranchProduct::factory()->for($product)->for($branch)->create(['is_available' => $overrideAvailable]);
    }

    expect(app(BranchCatalog::class)->isAvailable($product, $branch))->toBe($expected);
})->with([
    'not in the branch assortment' => [true, true, null, false],
    'enabled override' => [true, true, true, true],
    'disabled override' => [true, true, false, false],
    'inactive product' => [true, false, null, false],
    'inactive category' => [false, true, null, false],
    'override cannot enable product' => [true, false, true, false],
    'override cannot enable category' => [false, true, true, false],
    'both globally disabled' => [false, false, true, false],
]);

test('one branch availability override does not disable another branch or another product', function () {
    $main = Branch::factory()->create();
    $qave = Branch::factory()->create();
    $product = Product::factory()->soldAt($qave)->create();
    $otherProduct = Product::factory()->soldAt($main)->create();
    BranchProduct::factory()->for($product)->for($main)->create(['is_available' => false]);
    $product->load('branchProducts');
    $catalog = app(BranchCatalog::class);

    expect($catalog->isAvailable($product, $main))->toBeFalse();
    expect($catalog->isAvailable($product, $qave))->toBeTrue();
    expect($catalog->isAvailable($otherProduct, $main))->toBeTrue();
    expect($catalog->isAvailable($otherProduct, $qave))->toBeFalse();
});

test('a product without a branch row is not sold there and the browse list omits it and empty categories', function () {
    $branch = Branch::factory()->create();
    $sold = Product::factory()->soldAt($branch)->create(['name' => 'Sold here']);
    $notHere = Product::factory()->create(['name' => 'Sold nowhere']);
    BranchProduct::factory()->for($sold)->for(Branch::factory()->create())->create();

    $browse = app(BranchCatalog::class)->browse($branch);

    expect(array_column($browse['products'], 'name'))->toBe(['Sold here'])
        ->and(array_column($browse['categories'], 'id'))->toBe([$sold->category_id])
        ->and(app(BranchCatalog::class)->resolveLoaded(app(BranchCatalog::class)->productsForOrder($branch, [$notHere->id])->sole())['availability_reason'])->toBe('not_in_branch');
});

test('availability rechecks persisted global and branch disablement', function (string $disabled) {
    $product = Product::factory()->create();
    $branch = Branch::factory()->create();
    $override = BranchProduct::factory()->for($product)->for($branch)->create();
    $product->load('category', 'branchProducts');
    $catalog = app(BranchCatalog::class);
    expect($catalog->isAvailable($product, $branch))->toBeTrue();

    match ($disabled) {
        'category' => Category::query()->whereKey($product->category_id)->update(['is_active' => false]),
        'product' => Product::query()->whereKey($product->id)->update(['is_active' => false]),
        'override' => $override->update(['is_available' => false]),
    };

    expect($catalog->isAvailable($product, $branch))->toBeFalse();
})->with(['category', 'product', 'override']);

test('catalog availability is independent of store sessions and branch status', function (BranchStatus $status) {
    $branch = Branch::factory()->create(['status' => $status]);
    $product = Product::factory()->soldAt($branch)->create();
    $catalog = app(BranchCatalog::class);
    expect($catalog->isAvailable($product, $branch))->toBeTrue();

    $session = StoreSession::factory()->for($branch)->create();
    expect($catalog->isAvailable($product, $branch))->toBeTrue();
    $session->update(['status' => 'closed']);

    expect($catalog->isAvailable($product, $branch))->toBeTrue();
})->with(BranchStatus::cases());
