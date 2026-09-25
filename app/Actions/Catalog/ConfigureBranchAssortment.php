<?php

namespace App\Actions\Catalog;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Operations\CopyOperationsSetup;
use App\Enums\BranchStatus;
use App\Events\ReportsChanged;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\OperationPlanProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\BranchConfiguration;
use App\Support\CatalogRealtime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk Branch assortment writes. A Product is one canonical global definition; a Branch sells it only while it has a
 * `branch_products` row (explicit membership: price override, availability, tracking, low-stock threshold, recipe
 * mode). No row means the Product is not part of that Branch; `is_available = false` means it still belongs there but is
 * temporarily unavailable. These writes never duplicate a Product, Category, Modifier Group or Option, and never copy or
 * zero stock, movements, Store Sessions, sales or Orders.
 *
 * Both the destination and any source Branch are authorized here, never trusted from the browser: a Branch-scoped
 * Product manager reaches only its own assigned Branches. Every write first takes the Branch configuration lock
 * (BranchConfiguration), so it serializes with that Branch's sales: a sale commits entirely before a removal or fails
 * cleanly after it. Rows are written through the unique (branch_id, product_id) key with INSERT … ON CONFLICT DO NOTHING
 * and row locks in Product-id order, so a double submit or a racing request ends with exactly one row per Product.
 */
class ConfigureBranchAssortment
{
    /** The largest bulk selection accepted in one request. */
    public const MAX_PRODUCTS = 500;

    public function __construct(
        private UpsertBranchProduct $upsert,
        private CopyOperationsSetup $operations,
        private AuditRecorder $audit,
        private CatalogRealtime $realtime,
    ) {}

    /**
     * Adds existing canonical Products to the Branch assortment with default configuration (default price, available,
     * untracked, recipe mode unset). Products already in the assortment are left unchanged, so a retry is a no-op.
     *
     * @param  array<int, mixed>  $productIds
     * @return array{added: int, unchanged: int}
     */
    public function add(User $actor, Branch $branch, array $productIds): array
    {
        $this->authorizeBranch($actor, $branch);
        $products = $this->products($productIds);

        return DB::transaction(function () use ($actor, $branch, $products): array {
            $branch = BranchConfiguration::lock($branch);
            $added = array_values(array_filter($products, fn (Product $product): bool => $this->insertDefault($branch, $product)));

            if ($added !== []) {
                $this->audit->record(
                    branch: $branch,
                    actor: $actor,
                    module: 'products',
                    action: 'branch_products.added',
                    auditableType: Branch::class,
                    auditableId: $branch->id,
                    after: ['branch_code' => $branch->code, 'product_ids' => array_map(fn (Product $product): string => $product->id, $added)],
                    metadata: ['product_names' => array_map(fn (Product $product): string => $product->name, $added), 'count' => count($added)],
                );
                $this->realtime->branchProductsChanged($branch, array_map(fn (Product $product): string => $product->id, $added));
                ReportsChanged::dispatch((string) $branch->id, 'branch_products.added');
            }

            return ['added' => count($added), 'unchanged' => count($products) - count($added)];
        });
    }

    /**
     * Removes Products from the Branch assortment: POS and Customer QR stop selling them there and they leave that
     * Branch's Plans. The global Product, other Branches, the Branch's Product stock balance and every historical
     * movement, Order, recipe snapshot and purchase are untouched (stock is never zeroed; adding the Product back later
     * finds its balance and history). The Branch's own recipes and add-on effects stay dormant and apply again if the
     * Product is added back. Products not in the assortment are left unchanged.
     *
     * @param  array<int, mixed>  $productIds
     * @return array{removed: int, unchanged: int}
     */
    public function remove(User $actor, Branch $branch, array $productIds): array
    {
        $this->authorizeBranch($actor, $branch);
        $products = $this->products($productIds);

        return DB::transaction(function () use ($actor, $branch, $products): array {
            $branch = BranchConfiguration::lock($branch);
            $ids = array_map(fn (Product $product): string => $product->id, $products);
            $rows = BranchProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $ids)
                ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $removed = array_values(array_filter($products, fn (Product $product): bool => $rows->has($product->id)));
            $removedIds = array_map(fn (Product $product): string => $product->id, $removed);

            if ($removed !== []) {
                OperationPlanProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $removedIds)->delete();
                BranchProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $removedIds)->delete();
                $this->audit->record(
                    branch: $branch,
                    actor: $actor,
                    module: 'products',
                    action: 'branch_products.removed',
                    auditableType: Branch::class,
                    auditableId: $branch->id,
                    before: ['branch_code' => $branch->code, 'configurations' => $rows->only($removedIds)->map(fn (BranchProduct $row): array => [
                        'price_override' => $row->price_override,
                        'is_available' => $row->is_available,
                        'tracks_inventory' => $row->tracks_inventory,
                        'no_recipe_needed' => $row->no_recipe_needed,
                    ])->all()],
                    metadata: ['product_names' => array_map(fn (Product $product): string => $product->name, $removed), 'count' => count($removed)],
                );
                $this->realtime->branchProductsChanged($branch, $removedIds);
                ReportsChanged::dispatch((string) $branch->id, 'branch_products.removed');
            }

            return ['removed' => count($removed), 'unchanged' => count($products) - count($removed)];
        });
    }

    /**
     * Copy the selected Products from another authorized Branch that sells them: assortment membership plus the
     * source's Branch configuration (price, availability, tracking, threshold), and optionally their Operations setup
     * (recipe mode, recipes, add-on effects, the Ingredients they need, their Plans). A Product already in the
     * destination is kept unless $overwrite was explicitly confirmed. Physical stock is never copied: a tracked Product
     * starts with the destination's own (possibly empty) stock.
     *
     * @param  array<int, mixed>  $productIds
     * @return array{copied: int, overwritten: int, skipped: int, not_sold: int, operations: array<string, mixed>|null}
     */
    public function copy(User $actor, Branch $source, Branch $destination, array $productIds, bool $overwrite, bool $withOperations = false): array
    {
        $this->authorizeSource($actor, $source, $destination);
        $this->authorizeBranch($actor, $destination);
        if ($withOperations && ! $actor->hasPermission('operations.manage')) {
            throw new AuthorizationException('Copying Operations setup needs Operations access.');
        }
        $products = $this->products($productIds);

        return DB::transaction(function () use ($actor, $source, $destination, $products, $overwrite, $withOperations): array {
            [$source, $destination] = BranchConfiguration::lockCopy($source, $destination);
            $sourceRows = BranchProduct::query()->where('branch_id', $source->id)
                ->whereIn('product_id', array_map(fn (Product $product): string => $product->id, $products))
                ->get()->keyBy('product_id');
            $copied = [];
            $overwritten = [];
            $skipped = 0;
            $notSold = 0;
            foreach ($products as $product) {
                /** Only Products the source sells can be copied from it. */
                $sourceRow = $sourceRows->get($product->id);
                if ($sourceRow === null) {
                    $notSold++;

                    continue;
                }
                $inserted = $this->insertDefault($destination, $product);
                if (! $inserted && ! $overwrite) {
                    $skipped++;

                    continue;
                }
                $this->upsert->execute($actor, $destination, $product, [
                    'price_override' => $sourceRow->price_override,
                    'is_available' => $sourceRow->is_available,
                    'tracks_inventory' => $sourceRow->tracks_inventory,
                    'low_stock_threshold' => $sourceRow->low_stock_threshold,
                ]);
                if ($inserted) {
                    $copied[] = $product;
                } else {
                    $overwritten[] = $product;
                }
            }

            $changed = [...$copied, ...$overwritten];
            $changedIds = array_map(fn (Product $product): string => $product->id, $changed);
            if ($changed !== []) {
                $this->audit->record(
                    branch: $destination,
                    actor: $actor,
                    module: 'products',
                    action: 'branch_products.copied',
                    auditableType: Branch::class,
                    auditableId: $destination->id,
                    after: [
                        'source_branch_code' => $source->code,
                        'destination_branch_code' => $destination->code,
                        'product_ids' => $changedIds,
                    ],
                    metadata: ['copied' => count($copied), 'overwritten' => count($overwritten), 'skipped' => $skipped, 'overwrite' => $overwrite, 'operations' => $withOperations],
                );
                $this->realtime->branchProductsChanged($destination, $changedIds);
                ReportsChanged::dispatch((string) $destination->id, 'branch_products.copied');
            }
            /** Operations setup follows every selected Product now in the destination (new, overwritten or kept). */
            $operations = $withOperations
                ? $this->operations->copyForProducts($actor, $source, $destination, array_values(array_map('strval', $sourceRows->keys()->all())), $overwrite)
                : null;

            return ['copied' => count($copied), 'overwritten' => count($overwritten), 'skipped' => $skipped, 'not_sold' => $notSold, 'operations' => $operations];
        });
    }

    /**
     * Review rows for copying from $source to $destination: every Product the source sells, with its source
     * configuration and whether the destination already sells it, plus the Operations setup each would bring along.
     * Read only, after the same authorization.
     *
     * @return array{products: list<array{product_id: string, name: string, category_name: string, is_active: bool, source: array{configured: bool, sold: bool, price_override: string|null, effective_price: string, tracks_inventory: bool, low_stock_threshold: int|null}, destination: array{configured: bool, sold: bool, effective_price: string}}>, operations: array<string, mixed>|null}
     */
    public function preview(User $actor, Branch $source, Branch $destination): array
    {
        $this->authorizeSource($actor, $source, $destination);
        $this->authorizeBranch($actor, $destination);
        $rows = BranchProduct::query()->whereIn('branch_id', [$source->id, $destination->id])->get()
            ->groupBy('branch_id');
        $sourceRows = ($rows->get($source->id) ?? collect())->keyBy('product_id');
        $destinationRows = ($rows->get($destination->id) ?? collect())->keyBy('product_id');

        return [
            'products' => array_values(Product::query()->with('category:id,name')->whereKey($sourceRows->keys()->all())
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'category_id', 'default_price', 'is_active'])
                ->map(function (Product $product) use ($sourceRows, $destinationRows): array {
                    /** @var BranchProduct $sourceRow */
                    $sourceRow = $sourceRows->get($product->id);
                    /** @var BranchProduct|null $destinationRow */
                    $destinationRow = $destinationRows->get($product->id);

                    return [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'category_name' => $product->category->name,
                        'is_active' => $product->is_active,
                        'source' => [
                            'configured' => true,
                            'sold' => $sourceRow->is_available,
                            'price_override' => $sourceRow->price_override,
                            'effective_price' => (string) ($sourceRow->price_override ?? $product->default_price),
                            'tracks_inventory' => $sourceRow->tracks_inventory,
                            'low_stock_threshold' => $sourceRow->low_stock_threshold,
                        ],
                        'destination' => [
                            'configured' => $destinationRow !== null,
                            'sold' => $destinationRow !== null && $destinationRow->is_available,
                            'effective_price' => (string) ($destinationRow->price_override ?? $product->default_price),
                        ],
                    ];
                })->all()),
            /** Only an Operations manager may bring Operations setup along. */
            'operations' => $actor->hasPermission('operations.manage') ? $this->operations->productDetails($actor, $source, $destination) : null,
        ];
    }

    /**
     * Branches this account may copy Product configuration from into $destination: every other active Branch for a
     * business-wide account, only its other assigned active Branches for a Branch-scoped one.
     *
     * @return list<array{id: string, name: string, code: string}>
     */
    public static function copySources(User $actor, Branch $destination): array
    {
        return CopyOperationsSetup::sources($actor, $destination);
    }

    private function authorizeBranch(User $actor, Branch $branch): void
    {
        Gate::forUser($actor)->authorize('products.manage');
        if (! $actor->canAccessBranch($branch)) {
            throw new AuthorizationException('This account may not configure Products at this Branch.');
        }
    }

    /**
     * The source must be another active Branch this account may read: any for business-wide, an assigned one for a
     * Branch-scoped account (a MAIN-only manager never reads QAVE configuration through Copy).
     */
    private function authorizeSource(User $actor, Branch $source, Branch $destination): void
    {
        Gate::forUser($actor)->authorize('products.manage');
        if (! $actor->canAccessBranch($source)) {
            throw new AuthorizationException('This account may not read Product configuration of that Branch.');
        }
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['source_branch_id' => 'Choose a different Branch to copy from.']);
        }
        if ($source->status !== BranchStatus::Active) {
            throw ValidationException::withMessages(['source_branch_id' => 'Choose an active Branch to copy from.']);
        }
    }

    /**
     * @param  array<int, mixed>  $productIds
     * @return list<Product>
     */
    private function products(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (mixed $id): string => is_string($id) ? strtolower($id) : '', $productIds), fn (string $id): bool => Str::isUuid($id))));
        if ($ids === [] || count($ids) !== count($productIds)) {
            throw ValidationException::withMessages(['product_ids' => 'Choose at least one Product from the list.']);
        }
        if (count($ids) > self::MAX_PRODUCTS) {
            throw ValidationException::withMessages(['product_ids' => 'Choose at most '.self::MAX_PRODUCTS.' Products at a time.']);
        }
        $products = Product::query()->whereKey($ids)->orderBy('id')->get();
        if ($products->count() !== count($ids)) {
            throw ValidationException::withMessages(['product_ids' => 'Choose Products from the list only.']);
        }

        return array_values($products->all());
    }

    /** Creates the default membership row if none exists; true when this request created it. */
    private function insertDefault(Branch $branch, Product $product): bool
    {
        return BranchProduct::query()->insertOrIgnore([[
            'id' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'price_override' => null,
            'is_available' => true,
            'tracks_inventory' => false,
            'low_stock_threshold' => null,
            'no_recipe_needed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]]) === 1;
    }
}
