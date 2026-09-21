import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Boxes,
    History,
    ImageIcon,
    PackageSearch,
    SlidersHorizontal,
} from 'lucide-react';
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
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerPanelClass,
} from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/inventory';
import { index as movementsIndex } from '@/routes/inventory/movements';
import { index as productsIndex } from '@/routes/products';
import type { Auth, BranchSummary } from '@/types';
import type {
    InventoryFilters,
    InventoryPagination as Paginated,
    InventoryProduct,
    InventorySummary,
    StockStatus,
} from '@/types/inventory';

type Category = { id: string; name: string };
type Props = {
    branches: BranchSummary[];
    selectedBranch: BranchSummary | null;
    filters: InventoryFilters;
    categories: Category[];
    summary: InventorySummary;
    products: Paginated<InventoryProduct>;
};

const updatedDate = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

const summaryCards: {
    status: StockStatus;
    label: string;
    tone: 'green' | 'amber' | 'red' | 'neutral';
}[] = [
    { status: 'in_stock', label: 'In stock', tone: 'green' },
    { status: 'low_stock', label: 'Low stock', tone: 'amber' },
    { status: 'out_of_stock', label: 'Out of stock', tone: 'red' },
    { status: 'not_tracked', label: 'Not tracked', tone: 'neutral' },
];

export default function Inventory(props: Props) {
    const { selectedBranch, filters, summary, products } = props;
    const { auth } = usePage<{ auth: Auth }>().props;
    const [adjustingProductId, setAdjustingProductId] = useState<string | null>(
        null,
    );
    const adjustingProduct = products.data.find(
        (product) => product.id === adjustingProductId,
    );

    const visit = (
        changes: Partial<
            InventoryFilters & { branch_id: string; page: number }
        >,
    ) => {
        router.get(
            index.url(),
            {
                branch_id: selectedBranch?.id,
                search: filters.search,
                category: filters.category,
                stock_status: filters.stock_status,
                ...changes,
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <Head title="Inventory" />
            <OwnerPage
                title="Inventory"
                description="Monitor branch stock, find shortages quickly, and keep an auditable adjustment history."
                action={
                    auth.permissions.includes('products.manage') ? (
                        <Link
                            href={productsIndex()}
                            className={`${actionClass} inline-flex w-full items-center justify-center gap-2 md:w-auto`}
                        >
                            <Boxes className="size-4" /> Product management
                        </Link>
                    ) : undefined
                }
            >
                {selectedBranch ? (
                    <>
                        <section
                            aria-label="Inventory summary"
                            className="grid grid-cols-2 gap-2 lg:grid-cols-4"
                        >
                            {summaryCards.map((card) => {
                                const active =
                                    filters.stock_status === card.status;
                                return (
                                    <button
                                        key={card.status}
                                        type="button"
                                        aria-pressed={active}
                                        onClick={() =>
                                            visit({
                                                stock_status: active
                                                    ? 'all'
                                                    : card.status,
                                                page: 1,
                                            })
                                        }
                                        className={`${ownerPanelClass} min-h-[92px] p-3 text-left transition hover:border-[#bdbdbd] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${active ? 'border-[#111] ring-1 ring-[#111]' : ''}`}
                                    >
                                        <OwnerStatusBadge tone={card.tone}>
                                            {card.label}
                                        </OwnerStatusBadge>
                                        <p className="mt-2 text-[24px] leading-none font-bold tabular-nums">
                                            {summary[
                                                card.status
                                            ].toLocaleString()}
                                        </p>
                                    </button>
                                );
                            })}
                        </section>
                        <InventoryFiltersForm {...props} />
                        <div className="flex flex-wrap items-center justify-between gap-2 text-[11.5px] text-[#767676]">
                            <p role="status">
                                {products.total} products ·{' '}
                                {selectedBranch.name} ({selectedBranch.code})
                            </p>
                            <p>
                                Thresholds are configured in Product management.
                            </p>
                        </div>
                        {products.data.length === 0 ? (
                            <div
                                className={`${ownerPanelClass} px-5 py-14 text-center`}
                            >
                                <PackageSearch className="mx-auto size-7 text-[#aaa]" />
                                <h2 className="mt-3 text-sm font-semibold">
                                    No products found
                                </h2>
                                <p className="mt-1 text-[12.5px] text-[#767676]">
                                    Change the search or filters to view other
                                    stock.
                                </p>
                            </div>
                        ) : (
                            <div
                                className={`${ownerPanelClass} overflow-hidden`}
                            >
                                <div className="hidden grid-cols-[minmax(240px,1.6fr)_120px_100px_155px_210px] items-center gap-3 border-b border-[#e8e8e8] bg-[#fafafa] px-4 py-2.5 text-[10px] font-semibold tracking-[0.06em] text-[#767676] uppercase min-[980px]:grid">
                                    <span>Product</span>
                                    <span>Status</span>
                                    <span>On hand</span>
                                    <span>Last updated</span>
                                    <span className="text-right">Actions</span>
                                </div>
                                <ul className="divide-y divide-[#eeeeee]">
                                    {products.data.map((product) => (
                                        <InventoryRow
                                            key={product.id}
                                            product={product}
                                            branch={selectedBranch}
                                            onAdjust={() =>
                                                setAdjustingProductId(
                                                    product.id,
                                                )
                                            }
                                        />
                                    ))}
                                </ul>
                            </div>
                        )}
                        <InventoryPagination
                            currentPage={products.current_page}
                            lastPage={products.last_page}
                            label="Inventory pagination"
                            onPageChange={(page) => visit({ page })}
                        />
                    </>
                ) : (
                    <div
                        className={`${ownerPanelClass} px-5 py-14 text-center`}
                    >
                        <PackageSearch className="mx-auto size-7 text-[#aaa]" />
                        <h2 className="mt-3 text-sm font-semibold">
                            No branches
                        </h2>
                        <p className="mt-1 text-[12.5px] text-[#767676]">
                            Inventory becomes available after a branch is added.
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
            </OwnerPage>
        </>
    );
}

function InventoryRow({
    product,
    branch,
    onAdjust,
}: {
    product: InventoryProduct;
    branch: BranchSummary;
    onAdjust: () => void;
}) {
    return (
        <li className="grid min-w-0 gap-3 px-3.5 py-3 min-[980px]:grid-cols-[minmax(240px,1.6fr)_120px_100px_155px_210px] min-[980px]:items-center min-[980px]:px-4">
            <div className="flex min-w-0 items-center gap-3">
                <InventoryThumbnail product={product} />
                <div className="min-w-0">
                    <h2 className="truncate text-[13px] font-semibold">
                        {product.name}
                    </h2>
                    <p className="mt-0.5 truncate text-[11.5px] text-[#767676]">
                        {product.category_name}
                    </p>
                </div>
            </div>
            <div>
                <StockStatusBadge status={product.status} />
            </div>
            <div className="flex items-baseline justify-between min-[980px]:block">
                <span className="text-[11px] text-[#767676] min-[980px]:hidden">
                    On hand
                </span>
                <span className="text-sm font-bold tabular-nums">
                    {product.on_hand?.toLocaleString() ?? '—'}
                </span>
            </div>
            <div className="flex items-baseline justify-between gap-2 text-[11.5px] min-[980px]:block">
                <span className="text-[#767676] min-[980px]:hidden">
                    Last updated
                </span>
                <span className="text-right text-[#555] min-[980px]:text-left">
                    {product.last_updated_at
                        ? updatedDate.format(new Date(product.last_updated_at))
                        : 'No stock update'}
                </span>
            </div>
            <div className="grid grid-cols-2 gap-1.5 min-[980px]:flex min-[980px]:justify-end">
                <Button
                    className={primaryActionClass}
                    disabled={!product.tracked}
                    onClick={onAdjust}
                >
                    <SlidersHorizontal className="size-3.5" />{' '}
                    {product.tracked ? 'Adjust' : 'Not tracked'}
                </Button>
                <Button variant="outline" className={actionClass} asChild>
                    <Link
                        href={movementsIndex({
                            branch: branch.id,
                            product: product.id,
                        })}
                    >
                        <History className="size-3.5" /> History
                    </Link>
                </Button>
            </div>
        </li>
    );
}

function InventoryThumbnail({ product }: { product: InventoryProduct }) {
    const [failed, setFailed] = useState(false);
    return product.image_url && !failed ? (
        <img
            src={product.image_url}
            alt=""
            loading="lazy"
            width={46}
            height={46}
            onError={() => setFailed(true)}
            className="size-[46px] shrink-0 rounded-[10px] bg-[#f2f2f2] object-cover"
        />
    ) : (
        <span className="flex size-[46px] shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f2] text-[#aaa]">
            <ImageIcon className="size-4" />
        </span>
    );
}

function InventoryFiltersForm({
    branches,
    selectedBranch,
    filters,
    categories,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [category, setCategory] = useState(filters.category ?? '');
    const [stockStatus, setStockStatus] = useState(
        filters.stock_status ?? 'all',
    );
    const [loading, setLoading] = useState(false);
    const applyFilters = (branchId = selectedBranch?.id ?? '') => {
        setLoading(true);
        router.get(
            index.url(),
            {
                branch_id: branchId,
                search,
                category,
                stock_status: stockStatus,
            },
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
                if (!loading) applyFilters();
            }}
            className={`${ownerPanelClass} p-3`}
        >
            <fieldset
                disabled={loading}
                className="grid min-w-0 gap-2 sm:grid-cols-2 xl:grid-cols-[180px_minmax(220px,1fr)_180px_170px_auto]"
            >
                <label>
                    <span className="sr-only">Branch</span>
                    <select
                        aria-label="Branch"
                        className={controlClass}
                        value={selectedBranch?.id ?? ''}
                        onChange={(event) => applyFilters(event.target.value)}
                    >
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.name} ({branch.code})
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    <span className="sr-only">Search products</span>
                    <input
                        type="search"
                        className={controlClass}
                        value={search}
                        maxLength={255}
                        placeholder="Search products"
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </label>
                <label>
                    <span className="sr-only">Category</span>
                    <select
                        aria-label="Category"
                        className={controlClass}
                        value={category}
                        onChange={(event) => setCategory(event.target.value)}
                    >
                        <option value="">All categories</option>
                        {categories.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    <span className="sr-only">Stock status</span>
                    <select
                        aria-label="Stock status"
                        className={controlClass}
                        value={stockStatus}
                        onChange={(event) =>
                            setStockStatus(
                                event.target.value as StockStatus | 'all',
                            )
                        }
                    >
                        <option value="all">All stock</option>
                        {Object.entries(stockStatusLabels).map(
                            ([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                </label>
                <Button type="submit" variant="outline" className={actionClass}>
                    Apply filters
                </Button>
            </fieldset>
        </form>
    );
}
