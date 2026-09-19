import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    actionClass,
    controlClass,
    primaryActionClass,
} from '@/components/catalog-ui';
import { InventoryAdjustmentDialog } from '@/components/inventory-adjustment-dialog';
import {
    InventoryPagination,
    StockStatusBadge,
    stockStatusLabels,
} from '@/components/inventory-ui';
import { ProductImage } from '@/components/product-forms';
import { Button } from '@/components/ui/button';
import { workspace } from '@/routes';
import { index } from '@/routes/inventory';
import { index as movementsIndex } from '@/routes/inventory/movements';
import { index as productsIndex } from '@/routes/products';
import type { Auth, BranchSummary } from '@/types';
import type {
    InventoryFilters,
    InventoryPagination as Paginated,
    InventoryProduct,
    StockStatus,
} from '@/types/inventory';

type Props = {
    branches: BranchSummary[];
    selectedBranch: BranchSummary | null;
    filters: InventoryFilters;
    products: Paginated<InventoryProduct>;
};

export default function Inventory({
    branches,
    selectedBranch,
    filters,
    products,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [adjustingProductId, setAdjustingProductId] = useState<string | null>(
        null,
    );
    const adjustingProduct = products.data.find(
        (product) => product.id === adjustingProductId,
    );

    return (
        <>
            <Head title="Inventory" />
            <div className="mx-auto flex max-w-6xl flex-col gap-4">
                <Link
                    href={workspace()}
                    className="flex min-h-11 w-fit items-center text-sm font-semibold underline underline-offset-4"
                >
                    Back to workspace
                </Link>
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-2">
                        <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                            INVENTORY
                        </p>
                        <h1 className="text-[17px] font-bold">Inventory</h1>
                        <p className="text-sm text-neutral-600">
                            Manage branch stock levels and review movement
                            history.
                        </p>
                    </div>
                    {auth.permissions.includes('products.manage') && (
                        <Link
                            href={productsIndex()}
                            className="inline-flex min-h-11 items-center rounded-xl border border-neutral-200 bg-white px-4 py-3 text-sm font-semibold hover:bg-neutral-100"
                        >
                            Product management
                        </Link>
                    )}
                </header>
                <InventoryFiltersForm
                    key={`${selectedBranch?.id}-${JSON.stringify(filters)}`}
                    branches={branches}
                    selectedBranch={selectedBranch}
                    filters={filters}
                />
                {selectedBranch ? (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-neutral-600">
                            <p role="status">
                                {products.total} products ·{' '}
                                {selectedBranch.name} ({selectedBranch.code})
                            </p>
                            <p>
                                Tracking and thresholds are configured in
                                Product management.
                            </p>
                        </div>
                        {products.data.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-neutral-300 bg-white p-6 text-center">
                                <h2 className="text-sm font-bold">
                                    No products found
                                </h2>
                                <p className="mt-2 text-sm text-neutral-600">
                                    Try another product name or stock status.
                                </p>
                            </div>
                        ) : (
                            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {products.data.map((product) => (
                                    <li
                                        key={product.id}
                                        className="flex min-w-0 flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm"
                                    >
                                        <ProductImage product={product} />
                                        <div className="flex flex-1 flex-col gap-3 p-3">
                                            <div className="space-y-1">
                                                <p className="text-xs break-words text-neutral-500">
                                                    {product.category_name}
                                                </p>
                                                <h2 className="text-sm font-bold break-words">
                                                    {product.name}
                                                </h2>
                                                <p className="text-xs break-words text-neutral-600">
                                                    {selectedBranch.name} (
                                                    {selectedBranch.code})
                                                </p>
                                            </div>
                                            <StockStatusBadge
                                                status={product.status}
                                            />
                                            <dl className="grid grid-cols-2 gap-x-2 gap-y-2 text-xs">
                                                <dt className="text-neutral-600">
                                                    Tracks inventory
                                                </dt>
                                                <dd className="text-right font-semibold">
                                                    {product.tracked
                                                        ? 'Yes'
                                                        : 'No'}
                                                </dd>
                                                <dt className="text-neutral-600">
                                                    On hand
                                                </dt>
                                                <dd className="text-right text-sm font-bold tabular-nums">
                                                    {product.on_hand?.toLocaleString() ??
                                                        '—'}
                                                </dd>
                                                <dt className="text-neutral-600">
                                                    Low stock threshold
                                                </dt>
                                                <dd className="text-right font-semibold tabular-nums">
                                                    {product.low_stock_threshold?.toLocaleString() ??
                                                        '—'}
                                                </dd>
                                            </dl>
                                            <div className="mt-auto grid gap-2">
                                                <Button
                                                    className={
                                                        primaryActionClass
                                                    }
                                                    disabled={!product.tracked}
                                                    onClick={() =>
                                                        setAdjustingProductId(
                                                            product.id,
                                                        )
                                                    }
                                                >
                                                    {product.tracked
                                                        ? 'Adjust stock'
                                                        : 'Stock not tracked'}
                                                </Button>
                                                <Link
                                                    href={movementsIndex({
                                                        branch: selectedBranch.id,
                                                        product: product.id,
                                                    })}
                                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm font-semibold hover:bg-neutral-100"
                                                >
                                                    View history
                                                </Link>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <InventoryPagination
                            currentPage={products.current_page}
                            lastPage={products.last_page}
                            label="Inventory pagination"
                            onPageChange={(page) =>
                                router.get(
                                    index.url({
                                        query: {
                                            ...filters,
                                            branch_id: selectedBranch.id,
                                            page,
                                        },
                                    }),
                                )
                            }
                        />
                    </>
                ) : (
                    <div className="rounded-xl border border-dashed border-neutral-300 bg-white p-6 text-center">
                        <h2 className="text-sm font-bold">No branches</h2>
                        <p className="mt-2 text-sm text-neutral-600">
                            Inventory will be available when a branch exists.
                        </p>
                    </div>
                )}
                {adjustingProduct && selectedBranch && (
                    <InventoryAdjustmentDialog
                        key={`${selectedBranch.id}-${adjustingProduct.id}`}
                        product={adjustingProduct}
                        branch={selectedBranch}
                        onClose={() => setAdjustingProductId(null)}
                    />
                )}
            </div>
        </>
    );
}

function InventoryFiltersForm({
    branches,
    selectedBranch,
    filters,
}: Pick<Props, 'branches' | 'selectedBranch' | 'filters'>) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [stockStatus, setStockStatus] = useState(
        filters.stock_status ?? 'all',
    );
    const [loading, setLoading] = useState(false);

    const applyFilters = (branchId: string) => {
        setLoading(true);
        router.get(
            index.url(),
            { branch_id: branchId, search, stock_status: stockStatus },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setLoading(false),
            },
        );
    };

    return (
        <form
            aria-busy={loading}
            onSubmit={(event) => {
                event.preventDefault();
                if (selectedBranch && !loading) applyFilters(selectedBranch.id);
            }}
        >
            <fieldset
                disabled={loading || !selectedBranch}
                className="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_2fr_1fr_auto]"
            >
                <label className="min-w-0 space-y-1 text-xs font-semibold">
                    Branch
                    <select
                        className={controlClass}
                        value={selectedBranch?.id ?? ''}
                        onChange={(event) => applyFilters(event.target.value)}
                    >
                        {!selectedBranch && (
                            <option value="">No branches</option>
                        )}
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.name} ({branch.code})
                            </option>
                        ))}
                    </select>
                </label>
                <label className="min-w-0 space-y-1 text-xs font-semibold">
                    Search products
                    <input
                        type="search"
                        className={controlClass}
                        value={search}
                        maxLength={255}
                        placeholder="Search by name…"
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </label>
                <label className="min-w-0 space-y-1 text-xs font-semibold">
                    Stock status
                    <select
                        className={controlClass}
                        value={stockStatus}
                        onChange={(event) =>
                            setStockStatus(
                                event.target.value as StockStatus | 'all',
                            )
                        }
                    >
                        <option value="all">All</option>
                        {Object.entries(stockStatusLabels).map(
                            ([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                </label>
                <Button
                    type="submit"
                    variant="outline"
                    className={`self-end ${actionClass}`}
                >
                    {loading ? 'Loading…' : 'Apply filters'}
                </Button>
            </fieldset>
        </form>
    );
}
