<?php

namespace App\Actions\Catalog;

use App\Actions\Audit\AuditRecorder;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogRealtime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk Branch assortment writes. A Product is one canonical definition; a Branch only holds its optional configuration
 * row (`branch_products`: sold here or not, price override, tracking, low-stock threshold). No row means the Product is
 * sold at the Branch at its default price. These writes only create or update those rows: they never duplicate a
 * Product, Category, Modifier Group or Option, and never copy stock, movements, Store Sessions, sales or Orders.
 *
 * Both the destination and any source Branch are authorized here, never trusted from the browser: a Branch-scoped
 * Product manager reaches only its own assigned Branches. Rows are written through the unique (branch_id, product_id)
 * key with INSERT … ON CONFLICT DO NOTHING under a Branch share lock and row locks in Product-id order, so a double
 * submit or a racing request ends with exactly one configuration per Product.
 */
class ConfigureBranchAssortment
{
    /** The largest bulk selection accepted in one request. */
    public const MAX_PRODUCTS = 500;

    public function __construct(private UpsertBranchProduct $upsert, private AuditRecorder $audit, private CatalogRealtime $realtime) {}

    /**
     * Sell existing canonical Products at the Branch again (they were removed from its assortment, `is_available` =
     * false). Existing prices, tracking and thresholds are kept; only "sold here" changes. Products already sold here
     * are left unchanged, so a retry is a no-op.
     *
     * @param  array<int, mixed>  $productIds
     * @return array{added: int, unchanged: int}
     */
    public function add(User $actor, Branch $branch, array $productIds): array
    {
        $this->authorizeBranch($actor, $branch);
        $products = $this->products($productIds);

        return DB::transaction(function () use ($actor, $branch, $products): array {
            $branch = Branch::query()->whereKey($branch->id)->sharedLock()->firstOrFail();
            $added = [];
            foreach ($products as $product) {
                /** No row means the Product is already sold here at its default price, so nothing is written. */
                $row = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->lockForUpdate()->first();
                if ($row !== null && ! $row->is_available) {
                    $row->forceFill(['is_available' => true])->save();
                    $added[] = $product;
                }
            }

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
            }

            return ['added' => count($added), 'unchanged' => count($products) - count($added)];
        });
    }

    /**
     * Copy the selected Products' configuration from another authorized Branch. A Product already configured at the
     * destination is skipped unless $overwrite was explicitly confirmed. Physical stock is never copied: a tracked
     * Product starts with the destination's own (possibly empty) stock.
     *
     * @param  array<int, mixed>  $productIds
     * @return array{copied: int, overwritten: int, skipped: int}
     */
    public function copy(User $actor, Branch $source, Branch $destination, array $productIds, bool $overwrite): array
    {
        $this->authorizeSource($actor, $source, $destination);
        $this->authorizeBranch($actor, $destination);
        $products = $this->products($productIds);

        return DB::transaction(function () use ($actor, $source, $destination, $products, $overwrite): array {
            $destination = Branch::query()->whereKey($destination->id)->sharedLock()->firstOrFail();
            $sourceRows = BranchProduct::query()->where('branch_id', $source->id)
                ->whereIn('product_id', array_map(fn (Product $product): string => $product->id, $products))
                ->get()->keyBy('product_id');
            $copied = [];
            $overwritten = [];
            $skipped = 0;
            foreach ($products as $product) {
                $inserted = $this->insertDefault($destination, $product);
                BranchProduct::query()->where('branch_id', $destination->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                if (! $inserted && ! $overwrite) {
                    $skipped++;

                    continue;
                }
                /** No source row means the source sells it at the default price, untracked. */
                $sourceRow = $sourceRows->get($product->id);
                $this->upsert->execute($actor, $destination, $product, [
                    'price_override' => $sourceRow?->price_override,
                    'is_available' => $sourceRow->is_available ?? true,
                    'tracks_inventory' => $sourceRow->tracks_inventory ?? false,
                    'low_stock_threshold' => $sourceRow?->low_stock_threshold,
                ]);
                if ($inserted) {
                    $copied[] = $product;
                } else {
                    $overwritten[] = $product;
                }
            }

            $changed = [...$copied, ...$overwritten];
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
                        'product_ids' => array_map(fn (Product $product): string => $product->id, $changed),
                    ],
                    metadata: ['copied' => count($copied), 'overwritten' => count($overwritten), 'skipped' => $skipped, 'overwrite' => $overwrite],
                );
                $this->realtime->branchProductsChanged($destination, array_map(fn (Product $product): string => $product->id, $changed));
            }

            return ['copied' => count($copied), 'overwritten' => count($overwritten), 'skipped' => $skipped];
        });
    }

    /**
     * Review rows for copying from $source to $destination: every canonical Product with the source configuration (or
     * its defaults) and whether the destination already configures it. Read only, after the same authorization.
     *
     * @return list<array{product_id: string, name: string, category_name: string, is_active: bool, source: array{configured: bool, sold: bool, price_override: string|null, effective_price: string, tracks_inventory: bool, low_stock_threshold: int|null}, destination: array{configured: bool, sold: bool, effective_price: string}}>
     */
    public function preview(User $actor, Branch $source, Branch $destination): array
    {
        $this->authorizeSource($actor, $source, $destination);
        $this->authorizeBranch($actor, $destination);
        $rows = BranchProduct::query()->whereIn('branch_id', [$source->id, $destination->id])->get()
            ->groupBy('branch_id');
        $sourceRows = ($rows->get($source->id) ?? collect())->keyBy('product_id');
        $destinationRows = ($rows->get($destination->id) ?? collect())->keyBy('product_id');

        return array_values(Product::query()->with('category:id,name')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'category_id', 'default_price', 'is_active'])
            ->map(function (Product $product) use ($sourceRows, $destinationRows): array {
                /** @var BranchProduct|null $sourceRow */
                $sourceRow = $sourceRows->get($product->id);
                /** @var BranchProduct|null $destinationRow */
                $destinationRow = $destinationRows->get($product->id);

                return [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'category_name' => $product->category->name,
                    'is_active' => $product->is_active,
                    'source' => [
                        'configured' => $sourceRow !== null,
                        'sold' => $sourceRow->is_available ?? true,
                        'price_override' => $sourceRow?->price_override,
                        'effective_price' => (string) ($sourceRow->price_override ?? $product->default_price),
                        'tracks_inventory' => $sourceRow->tracks_inventory ?? false,
                        'low_stock_threshold' => $sourceRow?->low_stock_threshold,
                    ],
                    'destination' => [
                        'configured' => $destinationRow !== null,
                        'sold' => $destinationRow->is_available ?? true,
                        'effective_price' => (string) ($destinationRow->price_override ?? $product->default_price),
                    ],
                ];
            })->all());
    }

    /**
     * Branches this account may copy Product configuration from into $destination: every other active Branch for a
     * business-wide account, only its other assigned active Branches for a Branch-scoped one.
     *
     * @return list<array{id: string, name: string, code: string}>
     */
    public static function copySources(User $actor, Branch $destination): array
    {
        $query = $actor->hasBusinessWideScope()
            ? Branch::query()
            : $actor->branches()->wherePivot('is_active', true);

        return array_values($query->where('branches.status', BranchStatus::Active)
            ->whereKeyNot($destination->id)
            ->orderBy('branches.name')->orderBy('branches.code')
            ->get(['branches.id', 'branches.name', 'branches.code'])
            ->map(fn (Branch $branch): array => ['id' => (string) $branch->id, 'name' => $branch->name, 'code' => $branch->code])
            ->all());
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
        $ids = array_values(array_unique(array_filter(array_map(fn (mixed $id): string => is_string($id) ? $id : '', $productIds), fn (string $id): bool => Str::isUuid($id))));
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

    /** Creates the default configuration row if none exists; true when this request created it. */
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
            'created_at' => now(),
            'updated_at' => now(),
        ]]) === 1;
    }
}
